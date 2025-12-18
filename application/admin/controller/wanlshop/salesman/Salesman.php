<?php

namespace app\admin\controller\wanlshop\salesman;

use app\common\controller\Backend;
use app\admin\model\wanlshop\salesman\Salesman as SalesmanModel;
use app\admin\model\wanlshop\salesman\SalesmanStats;
use app\admin\model\wanlshop\salesman\SalesmanTask;
use app\admin\model\wanlshop\salesman\SalesmanTaskProgress;
use think\Db;
use think\Exception;

/**
 * 业务员管理
 *
 * @icon fa fa-user-secret
 */
class Salesman extends Backend
{
    protected $model = null;
    protected $relationSearch = true;
    protected $searchFields = 'id,user.nickname,user.mobile';

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new SalesmanModel();
        $this->view->assign('statusList', $this->model->getStatusList());
    }

    /**
     * 业务员列表
     */
    public function index()
    {
        $this->request->filter(['strip_tags', 'trim']);

        if ($this->request->isAjax()) {
            if ($this->request->request('keyField')) {
                return $this->selectpage();
            }

            list($where, $sort, $order, $offset, $limit) = $this->buildparams();

            $list = $this->model
                ->with(['user', 'admin', 'stats'])
                ->where($where)
                ->order($sort, $order)
                ->paginate($limit);

            foreach ($list as $row) {
                $row->visible(['id', 'user_id', 'status', 'status_text', 'remark', 'createtime']);
                if ($row->user) {
                    $row->user->visible(['id', 'nickname', 'mobile', 'avatar', 'bonus_level']);
                }
                if ($row->admin) {
                    $row->admin->visible(['nickname']);
                }
            }

            return json(['total' => $list->total(), 'rows' => $list->items()]);
        }

        return $this->view->fetch();
    }

    /**
     * 添加业务员（指定用户为业务员）
     */
    public function add()
    {
        if ($this->request->isPost()) {
            $params = $this->request->post('row/a');
            if (!$params) {
                $this->error(__('Parameter %s can not be empty', ''));
            }

            $userId = $params['user_id'] ?? 0;
            if (!$userId) {
                $this->error('请选择用户');
            }

            // 检查用户是否存在
            $user = Db::name('user')->where('id', $userId)->find();
            if (!$user) {
                $this->error('用户不存在');
            }

            // 检查是否已是业务员
            $exists = $this->model->where('user_id', $userId)->find();
            if ($exists) {
                $this->error('该用户已经是业务员');
            }

            Db::startTrans();
            try {
                $data = [
                    'user_id' => $userId,
                    'status' => $params['status'] ?? 'normal',
                    'remark' => $params['remark'] ?? '',
                    'admin_id' => $this->auth->id,
                    'createtime' => time(),
                    'updatetime' => time()
                ];

                $result = $this->model->save($data);

                if ($result) {
                    // 初始化统计数据
                    SalesmanStats::refreshStats($this->model->id, $userId);

                    // 为业务员分配所有启用的任务
                    $this->assignTasksToSalesman($this->model->id, $userId);
                }

                Db::commit();
                $this->success();
            } catch (Exception $e) {
                Db::rollback();
                $this->error($e->getMessage());
            }
        }

        return $this->view->fetch();
    }

    /**
     * 编辑业务员
     */
    public function edit($ids = null)
    {
        $row = $this->model->get($ids);
        if (!$row) {
            $this->error(__('No Results were found'));
        }

        if ($this->request->isPost()) {
            $params = $this->request->post('row/a');
            if (!$params) {
                $this->error(__('Parameter %s can not be empty', ''));
            }

            try {
                $result = $row->save([
                    'status' => $params['status'] ?? $row->status,
                    'remark' => $params['remark'] ?? $row->remark,
                    'updatetime' => time()
                ]);

                if ($result !== false) {
                    $this->success();
                }
                $this->error(__('No rows were updated'));
            } catch (Exception $e) {
                $this->error($e->getMessage());
            }
        }

        $this->view->assign('row', $row);
        return $this->view->fetch();
    }

    /**
     * 删除业务员
     */
    public function del($ids = null)
    {
        if (!$this->request->isPost()) {
            $this->error(__('Invalid parameters'));
        }

        $ids = $ids ?: $this->request->post('ids');
        $row = $this->model->get($ids);
        if (!$row) {
            $this->error(__('No Results were found'));
        }

        // 检查是否有进行中的任务
        $hasOngoing = SalesmanTaskProgress::where('salesman_id', $ids)
            ->whereIn('state', [
                SalesmanTaskProgress::STATE_ONGOING,
                SalesmanTaskProgress::STATE_COMPLETED,
                SalesmanTaskProgress::STATE_AUDITED
            ])
            ->find();

        if ($hasOngoing) {
            $this->error('该业务员有未完成的任务，请先处理');
        }

        Db::startTrans();
        try {
            // 删除统计数据
            SalesmanStats::where('salesman_id', $ids)->delete();
            // 删除任务进度
            SalesmanTaskProgress::where('salesman_id', $ids)->delete();
            // 删除业务员
            $row->delete();

            Db::commit();
            $this->success();
        } catch (Exception $e) {
            Db::rollback();
            $this->error($e->getMessage());
        }
    }

    /**
     * 业务员详情
     */
    public function detail($ids = null)
    {
        $row = $this->model->with(['user', 'admin', 'stats'])->find($ids);
        if (!$row) {
            $this->error(__('No Results were found'));
        }

        // 刷新统计数据
        SalesmanStats::refreshStats($row->id, $row->user_id);
        $row = $this->model->with(['user', 'admin', 'stats'])->find($ids);

        // 获取任务进度列表
        $taskProgress = SalesmanTaskProgress::with(['task'])
            ->where('salesman_id', $ids)
            ->order('id', 'asc')
            ->select();

        // 获取邀请的用户列表（最近10条）
        $invitedUsers = Db::name('user')
            ->where('inviter_id', $row->user_id)
            ->order('createtime', 'desc')
            ->limit(10)
            ->field('id, nickname, mobile, createtime')
            ->select();

        // 获取邀请的商家列表（最近10条）
        $invitedShops = Db::name('wanlshop_shop')
            ->where('inviter_id', $row->user_id)
            ->order('createtime', 'desc')
            ->limit(10)
            ->field('id, shopname, mobile, createtime')
            ->select();

        $this->view->assign('row', $row);
        $this->view->assign('taskProgress', $taskProgress);
        $this->view->assign('invitedUsers', $invitedUsers);
        $this->view->assign('invitedShops', $invitedShops);

        return $this->view->fetch();
    }

    /**
     * 刷新统计数据
     */
    public function refreshStats($ids = null)
    {
        $row = $this->model->get($ids);
        if (!$row) {
            $this->error(__('No Results were found'));
        }

        try {
            SalesmanStats::refreshStats($row->id, $row->user_id);

            // 同时刷新任务进度
            $this->refreshTaskProgress($row->id, $row->user_id);

            $this->success('刷新成功');
        } catch (Exception $e) {
            $this->error('刷新失败：' . $e->getMessage());
        }
    }

    /**
     * 为业务员分配所有启用的任务
     */
    protected function assignTasksToSalesman($salesmanId, $userId)
    {
        $tasks = SalesmanTask::where('status', 'normal')->select();
        $now = time();

        foreach ($tasks as $task) {
            // 检查是否已有该任务的进度记录
            $exists = SalesmanTaskProgress::where('salesman_id', $salesmanId)
                ->where('task_id', $task->id)
                ->find();

            if (!$exists) {
                // 获取当前进度值
                $currentValue = SalesmanStats::getProgressValue($userId, $task->type);

                $progress = new SalesmanTaskProgress();
                $progress->save([
                    'salesman_id' => $salesmanId,
                    'task_id' => $task->id,
                    'current_count' => $task->isCountType() ? $currentValue : 0,
                    'current_amount' => $task->isAmountType() ? $currentValue : 0,
                    'state' => SalesmanTaskProgress::STATE_ONGOING,
                    'reward_amount' => $task->reward_amount,
                    'createtime' => $now,
                    'updatetime' => $now
                ]);

                // 检查是否已完成
                if ($progress->checkCompleted()) {
                    $progress->save([
                        'state' => SalesmanTaskProgress::STATE_COMPLETED,
                        'complete_time' => $now
                    ]);
                }
            }
        }
    }

    /**
     * 刷新业务员任务进度
     */
    protected function refreshTaskProgress($salesmanId, $userId)
    {
        $progressList = SalesmanTaskProgress::with(['task'])
            ->where('salesman_id', $salesmanId)
            ->where('state', SalesmanTaskProgress::STATE_ONGOING)
            ->select();

        $now = time();

        foreach ($progressList as $progress) {
            if (!$progress->task) {
                continue;
            }

            $currentValue = SalesmanStats::getProgressValue($userId, $progress->task->type);

            $updateData = ['updatetime' => $now];

            if ($progress->task->isCountType()) {
                $updateData['current_count'] = $currentValue;
            } else {
                $updateData['current_amount'] = $currentValue;
            }

            $progress->save($updateData);

            // 检查是否完成
            if ($progress->checkCompleted() && $progress->state == SalesmanTaskProgress::STATE_ONGOING) {
                $progress->save([
                    'state' => SalesmanTaskProgress::STATE_COMPLETED,
                    'complete_time' => $now
                ]);
            }
        }
    }
}
