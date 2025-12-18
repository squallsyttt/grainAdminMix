<?php

namespace app\admin\controller\wanlshop\salesman;

use app\common\controller\Backend;
use app\admin\model\wanlshop\salesman\SalesmanTaskProgress as ProgressModel;
use app\admin\model\wanlshop\salesman\SalesmanStats;
use think\Db;
use think\Exception;

/**
 * 任务进度管理
 *
 * @icon fa fa-line-chart
 */
class Progress extends Backend
{
    protected $model = null;
    protected $relationSearch = true;
    protected $searchFields = 'user.nickname,user.mobile,task.name';

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new ProgressModel();
        $this->view->assign('stateList', $this->model->getStateList());
    }

    /**
     * 任务进度列表
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
                ->with(['user', 'task'])
                ->where($where)
                ->order($sort, $order)
                ->paginate($limit);

            foreach ($list as $row) {
                $row->visible([
                    'id', 'user_id', 'task_id', 'current_count', 'current_amount',
                    'state', 'state_text', 'progress_percent', 'reward_amount',
                    'complete_time', 'audit_time', 'reward_time', 'createtime'
                ]);
                if ($row->user) {
                    $row->user->visible(['nickname', 'mobile']);
                }
                if ($row->task) {
                    $row->task->visible(['name', 'type', 'type_text', 'target_text']);
                }
            }

            return json(['total' => $list->total(), 'rows' => $list->items()]);
        }

        return $this->view->fetch();
    }

    /**
     * 待审核列表
     */
    public function pending()
    {
        $this->request->filter(['strip_tags', 'trim']);

        if ($this->request->isAjax()) {
            list($where, $sort, $order, $offset, $limit) = $this->buildparams();

            $list = $this->model
                ->with(['user', 'task'])
                ->where('state', ProgressModel::STATE_COMPLETED)
                ->where($where)
                ->order('complete_time', 'asc')
                ->paginate($limit);

            foreach ($list as $row) {
                $row->visible([
                    'id', 'user_id', 'task_id', 'current_count', 'current_amount',
                    'state', 'state_text', 'progress_percent', 'reward_amount',
                    'complete_time', 'createtime'
                ]);
                if ($row->user) {
                    $row->user->visible(['nickname', 'mobile']);
                }
                if ($row->task) {
                    $row->task->visible(['name', 'type', 'type_text', 'target_text', 'reward_amount']);
                }
            }

            return json(['total' => $list->total(), 'rows' => $list->items()]);
        }

        return $this->view->fetch();
    }

    /**
     * 审核任务完成
     */
    public function audit($ids = null)
    {
        $row = $this->model->with(['user', 'task'])->find($ids);
        if (!$row) {
            $this->error(__('No Results were found'));
        }

        if (!$row->canAudit()) {
            $this->error('当前状态不可审核');
        }

        if ($this->request->isPost()) {
            $params = $this->request->post('row/a');
            $action = $params['action'] ?? '';

            if ($action === 'pass') {
                // 审核通过
                try {
                    $row->save([
                        'state' => ProgressModel::STATE_AUDITED,
                        'audit_time' => time(),
                        'audit_admin_id' => $this->auth->id,
                        'audit_remark' => $params['audit_remark'] ?? '',
                        'updatetime' => time()
                    ]);
                    $this->success('审核通过');
                } catch (Exception $e) {
                    $this->error($e->getMessage());
                }
            } elseif ($action === 'reject') {
                // 审核拒绝（取消）
                try {
                    $row->save([
                        'state' => ProgressModel::STATE_CANCELLED,
                        'audit_time' => time(),
                        'audit_admin_id' => $this->auth->id,
                        'audit_remark' => $params['audit_remark'] ?? '',
                        'updatetime' => time()
                    ]);
                    $this->success('已拒绝');
                } catch (Exception $e) {
                    $this->error($e->getMessage());
                }
            } else {
                $this->error('无效操作');
            }
        }

        $this->view->assign('row', $row);
        return $this->view->fetch();
    }

    /**
     * 发放奖励
     */
    public function grant($ids = null)
    {
        $row = $this->model->with(['user', 'task'])->find($ids);
        if (!$row) {
            $this->error(__('No Results were found'));
        }

        if (!$row->canGrant()) {
            $this->error('当前状态不可发放奖励');
        }

        if ($this->request->isPost()) {
            $params = $this->request->post('row/a');

            Db::startTrans();
            try {
                // 记录实际发放金额（可以与任务配置不同）
                $actualReward = $params['reward_amount'] ?? $row->reward_amount;

                $row->save([
                    'state' => ProgressModel::STATE_GRANTED,
                    'reward_time' => time(),
                    'reward_admin_id' => $this->auth->id,
                    'reward_amount' => $actualReward,
                    'reward_remark' => $params['reward_remark'] ?? '',
                    'updatetime' => time()
                ]);

                // 更新统计数据
                if ($row->user_id) {
                    SalesmanStats::refreshStats($row->user_id);
                }

                Db::commit();
                $this->success('发放成功', null, [
                    'reward_amount' => $actualReward
                ]);
            } catch (Exception $e) {
                Db::rollback();
                $this->error($e->getMessage());
            }
        }

        $this->view->assign('row', $row);
        return $this->view->fetch();
    }

    /**
     * 取消任务进度
     */
    public function cancel($ids = null)
    {
        $row = $this->model->find($ids);
        if (!$row) {
            $this->error(__('No Results were found'));
        }

        if (!$row->canCancel()) {
            $this->error('当前状态不可取消');
        }

        if ($this->request->isPost()) {
            try {
                $row->save([
                    'state' => ProgressModel::STATE_CANCELLED,
                    'updatetime' => time()
                ]);
                $this->success('已取消');
            } catch (Exception $e) {
                $this->error($e->getMessage());
            }
        }

        $this->view->assign('row', $row);
        return $this->view->fetch();
    }

    /**
     * 批量刷新进度
     */
    public function batchRefresh()
    {
        if (!$this->request->isPost()) {
            $this->error(__('Invalid parameters'));
        }

        $ids = $this->request->post('ids');
        if (!$ids) {
            $this->error('请选择要刷新的记录');
        }

        $ids = is_array($ids) ? $ids : explode(',', $ids);
        $now = time();
        $updated = 0;

        foreach ($ids as $id) {
            $progress = $this->model->with(['user', 'task'])->find($id);
            if (!$progress || !$progress->user || !$progress->task) {
                continue;
            }

            // 只刷新进行中的任务
            if ($progress->state != ProgressModel::STATE_ONGOING) {
                continue;
            }

            $currentValue = SalesmanStats::getProgressValue(
                $progress->user_id,
                $progress->task->type
            );

            $updateData = ['updatetime' => $now];

            if ($progress->task->isCountType()) {
                $updateData['current_count'] = $currentValue;
            } else {
                $updateData['current_amount'] = $currentValue;
            }

            $progress->save($updateData);

            // 检查是否完成
            if ($progress->checkCompleted()) {
                $progress->save([
                    'state' => ProgressModel::STATE_COMPLETED,
                    'complete_time' => $now
                ]);
            }

            $updated++;
        }

        $this->success("已刷新 {$updated} 条记录");
    }
}
