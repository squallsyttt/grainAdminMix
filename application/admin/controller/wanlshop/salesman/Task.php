<?php

namespace app\admin\controller\wanlshop\salesman;

use app\common\controller\Backend;
use app\admin\model\wanlshop\salesman\SalesmanTask as TaskModel;
use app\admin\model\wanlshop\salesman\SalesmanTaskProgress;
use app\admin\model\wanlshop\salesman\SalesmanStats;
use think\Db;
use think\Exception;

/**
 * 任务配置管理
 *
 * @icon fa fa-tasks
 */
class Task extends Backend
{
    protected $model = null;

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new TaskModel();
        $this->view->assign('typeList', $this->model->getTypeList());
        $this->view->assign('statusList', $this->model->getStatusList());
    }

    /**
     * 任务列表
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
                ->where($where)
                ->order($sort, $order)
                ->paginate($limit);

            // 统计每个任务的完成情况
            foreach ($list as $row) {
                // 参与人数
                $row->participant_count = SalesmanTaskProgress::where('task_id', $row->id)->count();
                // 已完成人数
                $row->completed_count = SalesmanTaskProgress::where('task_id', $row->id)
                    ->whereIn('state', [
                        SalesmanTaskProgress::STATE_COMPLETED,
                        SalesmanTaskProgress::STATE_AUDITED,
                        SalesmanTaskProgress::STATE_GRANTED
                    ])
                    ->count();
            }

            return json(['total' => $list->total(), 'rows' => $list->items()]);
        }

        return $this->view->fetch();
    }

    /**
     * 添加任务
     */
    public function add()
    {
        if ($this->request->isPost()) {
            $params = $this->request->post('row/a');
            if (!$params) {
                $this->error(__('Parameter %s can not be empty', ''));
            }

            // 验证参数
            if (empty($params['name'])) {
                $this->error('请输入任务名称');
            }
            if (empty($params['type'])) {
                $this->error('请选择任务类型');
            }
            if (empty($params['reward_amount']) || $params['reward_amount'] <= 0) {
                $this->error('请输入有效的奖励金额');
            }

            // 根据类型验证目标值
            if (in_array($params['type'], [TaskModel::TYPE_USER_VERIFY, TaskModel::TYPE_SHOP_VERIFY])) {
                if (empty($params['target_count']) || $params['target_count'] <= 0) {
                    $this->error('请输入有效的目标数量');
                }
            } else {
                if (empty($params['target_amount']) || $params['target_amount'] <= 0) {
                    $this->error('请输入有效的目标金额');
                }
            }

            Db::startTrans();
            try {
                $data = [
                    'name' => $params['name'],
                    'type' => $params['type'],
                    'target_count' => $params['target_count'] ?? 0,
                    'target_amount' => $params['target_amount'] ?? 0,
                    'reward_amount' => $params['reward_amount'],
                    'description' => $params['description'] ?? '',
                    'status' => $params['status'] ?? 'normal',
                    'weigh' => $params['weigh'] ?? 0,
                    'createtime' => time(),
                    'updatetime' => time()
                ];

                $result = $this->model->save($data);

                if ($result) {
                    // 为所有现有业务员分配此任务
                    $this->assignTaskToAllSalesmen($this->model->id);
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
     * 编辑任务
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

            // 检查是否有已完成或待审核的进度，不允许修改目标值
            $hasCompleted = SalesmanTaskProgress::where('task_id', $ids)
                ->whereIn('state', [
                    SalesmanTaskProgress::STATE_COMPLETED,
                    SalesmanTaskProgress::STATE_AUDITED,
                    SalesmanTaskProgress::STATE_GRANTED
                ])
                ->find();

            if ($hasCompleted) {
                // 只允许修改名称、描述、状态
                $allowedFields = ['name', 'description', 'status', 'weigh'];
                foreach ($params as $key => $value) {
                    if (!in_array($key, $allowedFields)) {
                        unset($params[$key]);
                    }
                }
            }

            try {
                $params['updatetime'] = time();
                $result = $row->save($params);

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
     * 删除任务
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

        // 检查是否有待审核或待发放的进度
        $hasPending = SalesmanTaskProgress::where('task_id', $ids)
            ->whereIn('state', [
                SalesmanTaskProgress::STATE_COMPLETED,
                SalesmanTaskProgress::STATE_AUDITED
            ])
            ->find();

        if ($hasPending) {
            $this->error('该任务有待处理的进度记录，请先处理');
        }

        Db::startTrans();
        try {
            // 删除所有进行中的进度记录
            SalesmanTaskProgress::where('task_id', $ids)
                ->where('state', SalesmanTaskProgress::STATE_ONGOING)
                ->delete();

            // 删除任务
            $row->delete();

            Db::commit();
            $this->success();
        } catch (Exception $e) {
            Db::rollback();
            $this->error($e->getMessage());
        }
    }

    /**
     * 为所有业务员分配任务
     */
    protected function assignTaskToAllSalesmen($taskId)
    {
        $task = TaskModel::get($taskId);
        if (!$task || $task->status !== 'normal') {
            return;
        }

        // 获取所有业务员（is_salesman = 1）
        $salesmen = Db::name('user')->where('is_salesman', 1)->where('status', 'normal')->select();
        $now = time();

        foreach ($salesmen as $salesman) {
            $userId = $salesman['id'];

            // 检查是否已有该任务的进度记录
            $exists = SalesmanTaskProgress::where('user_id', $userId)
                ->where('task_id', $taskId)
                ->find();

            if (!$exists) {
                // 获取当前进度值
                $currentValue = SalesmanStats::getProgressValue($userId, $task->type);

                $progress = new SalesmanTaskProgress();
                $progress->save([
                    'user_id' => $userId,
                    'task_id' => $taskId,
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
}
