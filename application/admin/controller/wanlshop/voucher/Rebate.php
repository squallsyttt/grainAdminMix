<?php

namespace app\admin\controller\wanlshop\voucher;

use app\admin\service\RebateTransferService;
use app\admin\service\CustodyRefundService;
use app\common\controller\Backend;

/**
 * 返利记录
 *
 * @icon fa fa-undo
 */
class Rebate extends Backend
{
    /**
     * VoucherRebate模型对象
     * @var \app\admin\model\wanlshop\VoucherRebate
     */
    protected $model = null;

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new \app\admin\model\wanlshop\VoucherRebate;
        $this->view->assign("stageList", $this->model->getStageList());
        $this->view->assign("paymentStatusList", $this->model->getPaymentStatusList());
    }

    /**
     * 查看
     */
    public function index()
    {
        // 当前是否为关联查询
        $this->relationSearch = true;
        // 设置过滤方法
        $this->request->filter(['strip_tags', 'trim']);

        if ($this->request->isAjax()) {
            // 如果发送的来源是Selectpage，则转发到Selectpage
            if ($this->request->request('keyField')) {
                return $this->selectpage();
            }

            list($where, $sort, $order, $offset, $limit) = $this->buildparams();

            $total = $this->model
                ->with(['voucher', 'voucherOrder', 'user', 'shop'])
                ->where($where)
                ->order($sort, $order)
                ->count();

            $list = $this->model
                ->with(['voucher', 'voucherOrder', 'user', 'shop'])
                ->where($where)
                ->order($sort, $order)
                ->limit($offset, $limit)
                ->select();

            // 7天前的时间戳
            $sevenDaysAgo = time() - 7 * 86400;

            foreach ($list as $row) {
                $row->getRelation('user')->visible(['username', 'nickname']);
                $row->getRelation('shop')->visible(['shopname']);
                $row->getRelation('voucher')->visible(['voucher_no', 'goods_title']);
                // 添加 can_transfer 标志供前端判断
                $row->can_transfer = $row->canTransfer();
                // 添加距离可打款的剩余天数
                if ($row->payment_time >= $sevenDaysAgo) {
                    $row->days_until_transfer = ceil(($row->payment_time - $sevenDaysAgo) / 86400);
                } else {
                    $row->days_until_transfer = 0;
                }
            }

            $list = collection($list)->toArray();
            $result = array("total" => $total, "rows" => $list);

            return json($result);
        }
        return $this->view->fetch();
    }

    /**
     * 详情
     */
    public function detail($ids = null)
    {
        $row = $this->model
            ->with(['voucher', 'voucherOrder', 'user', 'shop', 'voucherRule', 'verification'])
            ->find($ids);
        if (!$row) {
            $this->error(__('No Results were found'));
        }

        $this->view->assign("row", $row);
        $this->view->assign("stageList", $this->model->getStageList());
        return $this->view->fetch();
    }

    /**
     * 打款弹窗（GET显示表单，POST执行打款）
     */
    public function transfer($ids = null)
    {
        $row = $this->model->get($ids);
        if (!$row) {
            $this->error(__('No Results were found'));
        }

        // 检查是否可以打款
        if (!$row->canTransfer()) {
            if ($row->payment_status === 'pending') {
                $this->error('当前状态为打款中，请等待用户确认');
            }
            if ($row->payment_status === 'paid') {
                $this->error('已完成打款，请勿重复操作');
            }
            $sevenDaysAgo = time() - 7 * 86400;
            if ($row->payment_time >= $sevenDaysAgo) {
                $daysLeft = ceil(($row->payment_time - $sevenDaysAgo) / 86400);
                $this->error("付款时间未满7天，还需等待 {$daysLeft} 天");
            }
            $this->error('当前状态不可打款');
        }

        $service = new RebateTransferService();

        if ($this->request->isPost()) {
            try {
                $result = $service->transfer((int)$ids);
                if ($result['success']) {
                    $this->success('打款发起成功', null, $result['data'] ?? []);
                }
                $this->error('打款失败：' . ($result['message'] ?? '未知错误'));
            } catch (\think\exception\HttpResponseException $e) {
                throw $e;
            } catch (\Exception $e) {
                $this->error('打款失败：' . $e->getMessage());
            }
        }

        // GET 请求：显示表单
        $receiver = $service->getReceiver((int)$row->user_id);

        // 直接使用表里的 rebate_amount 字段
        $rebateAmount = $row->rebate_amount;

        $this->view->assign('row', $row);
        $this->view->assign('receiver', $receiver);
        $this->view->assign('rebateAmount', $rebateAmount);
        return $this->view->fetch();
    }

    /**
     * 重试打款
     */
    public function retry()
    {
        $rebateId = $this->request->post('rebate_id');
        if (!$rebateId) {
            $this->error(__('Parameter %s can not be empty', 'rebate_id'));
        }

        try {
            $service = new RebateTransferService();
            $result = $service->retry((int)$rebateId);
            if (!empty($result['success'])) {
                $this->success('重试成功', null, $result['data'] ?? []);
            }
            $this->error('重试失败：' . ($result['message'] ?? '未知错误'));
        } catch (\think\exception\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->error('重试失败：' . $e->getMessage());
        }
    }

    /**
     * 重试代管理退款
     *
     * 用于重试失败的代管理等量退款
     */
    public function retryRefund()
    {
        $rebateId = $this->request->post('rebate_id');
        if (!$rebateId) {
            $this->error(__('Parameter %s can not be empty', 'rebate_id'));
        }

        try {
            $service = new CustodyRefundService();
            $result = $service->retryCustodyRefund((int)$rebateId);
            if (!empty($result['success'])) {
                $this->success('重试退款成功', null, $result['data'] ?? []);
            }
            $this->error('重试退款失败：' . ($result['message'] ?? '未知错误'));
        } catch (\think\exception\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->error('重试退款失败：' . $e->getMessage());
        }
    }
}
