<?php

namespace app\admin\controller\wanlshop\salesman;

use app\common\controller\Backend;
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
    protected $noNeedRight = ['selectuser'];

    public function _initialize()
    {
        parent::_initialize();
        $this->view->assign('statusList', [
            'normal' => '正常',
            'hidden' => '禁用'
        ]);
    }

    /**
     * 业务员列表
     */
    public function index()
    {
        $this->request->filter(['strip_tags', 'trim']);

        if ($this->request->isAjax()) {
            $search = $this->request->get('search', '');
            $sort = $this->request->get('sort', 'id');
            $order = $this->request->get('order', 'desc');
            $offset = $this->request->get('offset/d', 0);
            $limit = $this->request->get('limit/d', 10);

            $where = ['is_salesman' => 1];

            // 搜索条件
            if ($search) {
                $where['nickname|mobile'] = ['like', "%{$search}%"];
            }

            $list = Db::name('user')
                ->alias('u')
                ->join('__SALESMAN_STATS__ s', 's.user_id = u.id', 'LEFT')
                ->join('__ADMIN__ a', 'a.id = u.salesman_admin_id', 'LEFT')
                ->where($where)
                ->field('u.id, u.nickname, u.mobile, u.avatar, u.status, u.salesman_remark,
                    u.salesman_admin_id, u.createtime, u.bonus_level,
                    s.invite_user_count, s.invite_user_verified, s.invite_shop_count, s.invite_shop_verified,
                    s.total_rebate_amount, s.total_reward_amount, s.pending_reward_amount,
                    a.nickname as admin_nickname')
                ->order($sort, $order)
                ->paginate($limit);

            return json(['total' => $list->total(), 'rows' => $list->items()]);
        }

        return $this->view->fetch();
    }

    /**
     * 选择用户（用于添加业务员时选择）
     */
    public function selectuser()
    {
        if ($this->request->isAjax()) {
            $search = $this->request->get('q_word/a', []);
            $keyword = $search[0] ?? '';

            $list = Db::name('user')
                ->where('is_salesman', 0)
                ->where(function ($query) use ($keyword) {
                    if ($keyword) {
                        $query->where('nickname|mobile', 'like', "%{$keyword}%");
                    }
                })
                ->field('id, nickname, mobile, avatar')
                ->limit(20)
                ->select();

            $result = [];
            foreach ($list as $item) {
                $result[] = [
                    'id' => $item['id'],
                    'name' => $item['nickname'] . ' (' . $item['mobile'] . ')'
                ];
            }

            return json(['list' => $result]);
        }
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
            if ($user['is_salesman'] == 1) {
                $this->error('该用户已经是业务员');
            }

            Db::startTrans();
            try {
                // 设置为业务员
                Db::name('user')->where('id', $userId)->update([
                    'is_salesman' => 1,
                    'salesman_remark' => $params['remark'] ?? '',
                    'salesman_admin_id' => $this->auth->id,
                    'updatetime' => time()
                ]);

                // 初始化统计数据
                SalesmanStats::refreshStats($userId);

                // 为业务员分配所有启用的任务
                $this->assignTasksToSalesman($userId);

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
        $row = Db::name('user')->where('id', $ids)->where('is_salesman', 1)->find();
        if (!$row) {
            $this->error(__('No Results were found'));
        }

        if ($this->request->isPost()) {
            $params = $this->request->post('row/a');
            if (!$params) {
                $this->error(__('Parameter %s can not be empty', ''));
            }

            try {
                $result = Db::name('user')->where('id', $ids)->update([
                    'status' => $params['status'] ?? $row['status'],
                    'salesman_remark' => $params['remark'] ?? $row['salesman_remark'],
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
     * 删除业务员（取消业务员身份）
     */
    public function del($ids = null)
    {
        if (!$this->request->isPost()) {
            $this->error(__('Invalid parameters'));
        }

        $ids = $ids ?: $this->request->post('ids');
        $row = Db::name('user')->where('id', $ids)->where('is_salesman', 1)->find();
        if (!$row) {
            $this->error(__('No Results were found'));
        }

        // 检查是否有进行中的任务
        $hasOngoing = SalesmanTaskProgress::where('user_id', $ids)
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
            SalesmanStats::where('user_id', $ids)->delete();
            // 删除任务进度
            SalesmanTaskProgress::where('user_id', $ids)->delete();
            // 取消业务员身份
            Db::name('user')->where('id', $ids)->update([
                'is_salesman' => 0,
                'salesman_remark' => '',
                'salesman_admin_id' => null,
                'updatetime' => time()
            ]);

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
        $row = Db::name('user')->alias('u')
            ->join('__SALESMAN_STATS__ s', 's.user_id = u.id', 'LEFT')
            ->where('u.id', $ids)
            ->where('u.is_salesman', 1)
            ->field('u.*, s.invite_user_count, s.invite_user_verified, s.invite_shop_count,
                s.invite_shop_verified, s.total_rebate_amount, s.total_reward_amount, s.pending_reward_amount')
            ->find();

        if (!$row) {
            $this->error(__('No Results were found'));
        }

        // 刷新统计数据
        SalesmanStats::refreshStats($ids);

        // 重新获取数据
        $row = Db::name('user')->alias('u')
            ->join('__SALESMAN_STATS__ s', 's.user_id = u.id', 'LEFT')
            ->where('u.id', $ids)
            ->field('u.*, s.invite_user_count, s.invite_user_verified, s.invite_shop_count,
                s.invite_shop_verified, s.total_rebate_amount, s.total_reward_amount, s.pending_reward_amount')
            ->find();

        // 获取任务进度列表
        $taskProgress = SalesmanTaskProgress::with(['task'])
            ->where('user_id', $ids)
            ->order('id', 'asc')
            ->select();

        // 获取邀请的用户列表（最近10条）
        $invitedUsers = Db::name('user')
            ->where('inviter_id', $ids)
            ->order('createtime', 'desc')
            ->limit(10)
            ->field('id, nickname, mobile, createtime')
            ->select();

        // 获取邀请的商家列表（最近10条）
        $invitedShops = Db::name('wanlshop_shop')
            ->alias('s')
            ->join('__USER__ u', 'u.id = s.user_id', 'LEFT')
            ->where('s.inviter_id', $ids)
            ->order('s.createtime', 'desc')
            ->limit(10)
            ->field('s.id, s.shopname, u.mobile, s.createtime')
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
        $row = Db::name('user')->where('id', $ids)->where('is_salesman', 1)->find();
        if (!$row) {
            $this->error(__('No Results were found'));
        }

        try {
            SalesmanStats::refreshStats($ids);

            // 同时刷新任务进度
            $this->refreshTaskProgress($ids);

            $this->success('刷新成功');
        } catch (Exception $e) {
            $this->error('刷新失败：' . $e->getMessage());
        }
    }

    /**
     * 为业务员分配所有启用的任务
     */
    protected function assignTasksToSalesman($userId)
    {
        $tasks = SalesmanTask::where('status', 'normal')->select();
        $now = time();

        foreach ($tasks as $task) {
            // 检查是否已有该任务的进度记录
            $exists = SalesmanTaskProgress::where('user_id', $userId)
                ->where('task_id', $task->id)
                ->find();

            if (!$exists) {
                // 获取当前进度值
                $currentValue = SalesmanStats::getProgressValue($userId, $task->type);

                $progress = new SalesmanTaskProgress();
                $progress->save([
                    'user_id' => $userId,
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
    protected function refreshTaskProgress($userId)
    {
        $progressList = SalesmanTaskProgress::with(['task'])
            ->where('user_id', $userId)
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
