<?php

namespace app\admin\controller\wanlshop\voucher;

use app\admin\service\SettlementTransferService;
use app\common\controller\Backend;
use think\Db;

/**
 * 结算管理
 *
 * @icon fa fa-dollar
 */
class Settlement extends Backend
{
    /**
     * VoucherSettlement模型对象
     * @var \app\admin\model\wanlshop\VoucherSettlement
     */
    protected $model = null;

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new \app\admin\model\wanlshop\VoucherSettlement;
        $this->view->assign("stateList", $this->model->getStateList());
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
                ->with(['voucher', 'voucherOrder', 'user'])
                ->where($where)
                ->order($sort, $order)
                ->count();

            $list = $this->model
                ->with(['voucher', 'voucherOrder', 'user'])
                ->where($where)
                ->order($sort, $order)
                ->limit($offset, $limit)
                ->select();

            // 批量查询退款拦截信息（用于展示“不可结算/退款中/已退款”等状态）
            $voucherIds = [];
            foreach ($list as $row) {
                if (!empty($row->voucher_id)) {
                    $voucherIds[] = (int)$row->voucher_id;
                }
            }
            $voucherIds = array_values(array_unique(array_filter($voucherIds)));
            $refundMap = [];
            if (!empty($voucherIds)) {
                $refundRows = Db::name('wanlshop_voucher_refund')
                    ->where('voucher_id', 'in', $voucherIds)
                    ->where('state', 'in', ['0', '1', '3'])
                    ->where('status', 'normal')
                    ->whereNull('deletetime')
                    ->field('voucher_id,refund_no,state')
                    ->order('id', 'desc')
                    ->select();
                foreach ($refundRows as $r) {
                    $vid = (int)($r['voucher_id'] ?? 0);
                    if ($vid > 0 && !isset($refundMap[$vid])) {
                        $refundMap[$vid] = $r;
                    }
                }
            }

            foreach ($list as $row) {
                $row->getRelation('user')->visible(['username', 'nickname']);
                // 需要展示券状态，便于前端禁用“结算打款”（避免已退款仍可结算）
                $row->getRelation('voucher')->visible(['voucher_no', 'goods_title', 'state', 'state_text']);

                // 结算状态展示：当存在退款记录/券状态为退款相关时，显示更细颗粒度的状态文本
                $displayText = '';
                $displayClass = '';
                $voucherState = $row->voucher ? (string)$row->voucher->state : '';
                $refund = $refundMap[(int)$row->voucher_id] ?? null;
                $refundState = $refund ? (string)($refund['state'] ?? '') : '';

                if ($refundState === '3' || $voucherState === '4') {
                    $displayText = '已退款';
                    $displayClass = 'label-default';
                } elseif ($refundState === '0' || $refundState === '1' || $voucherState === '5') {
                    $displayText = '退款中';
                    $displayClass = 'label-info';
                }

                if ($displayText !== '') {
                    if ((string)$row->state === '2') {
                        $row['state_display_text'] = '已结算 / ' . $displayText;
                        $row['state_display_class'] = 'label-warning';
                    } else {
                        $row['state_display_text'] = $displayText;
                        $row['state_display_class'] = $displayClass;
                    }
                } else {
                    $row['state_display_text'] = '';
                    $row['state_display_class'] = '';
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
    public function detail($id = null)
    {
        $row = $this->model->get($id);
        if (!$row) {
            $this->error(__('No Results were found'));
        }

        // 关联信息
        $row->voucher;
        $row->voucherOrder;
        $row->user;

        $this->view->assign("row", $row);
        return $this->view->fetch();
    }

    /**
     * 获取收款人列表
     */
    public function getReceivers()
    {
        $settlementId = $this->request->post('settlement_id');
        if (!$settlementId) {
            $this->error(__('Parameter %s can not be empty', 'settlement_id'));
        }

        $settlement = $this->model->get($settlementId);
        if (!$settlement || !in_array((string)$settlement->state, ['1', '4'], true)) {
            $this->error('无效的结算记录');
        }

        $this->assertSettlementNotRefunded((int)$settlement->voucher_id);

        $service = new SettlementTransferService();
        $receivers = $service->getReceivers((int)$settlement->shop_id);

        $this->success('获取成功', null, $receivers);
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

        // 检查状态：只有待结算(1)或打款失败(4)才能打款
        if (!in_array((string)$row->state, ['1', '4'], true)) {
            $this->error('当前状态不可打款');
        }

        $this->assertSettlementNotRefunded((int)$row->voucher_id);

        $service = new SettlementTransferService();

        if ($this->request->isPost()) {
            $receiverUserId = $this->request->post('receiver_user_id');
            if (!$receiverUserId) {
                $this->error('请选择收款人');
            }

            try {
                $result = $service->transfer((int)$ids, (int)$receiverUserId);
                if ($result['success']) {
                    $this->success('打款发起成功', null, $result['data'] ?? []);
                }
                $this->error('打款失败1：' . ($result['message'] ?? '未知错误'));
            } catch (\think\exception\HttpResponseException $e) {
                throw $e;
            } catch (\Exception $e) {
                $this->error('打款失败2：' . $e->getMessage());
            }
        }

        // GET 请求：显示表单
        $receivers = $service->getReceivers((int)$row->shop_id);

        $this->view->assign('row', $row);
        $this->view->assign('receivers', $receivers);
        return $this->view->fetch();
    }

    /**
     * 重试打款
     */
    public function retry()
    {
        $settlementId = $this->request->post('settlement_id');
        if (!$settlementId) {
            $this->error(__('Parameter %s can not be empty', 'settlement_id'));
        }

        try {
            $service = new SettlementTransferService();
            $result = $service->retry((int)$settlementId);
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
     * 标记已结算
     */
    public function settle()
    {
        $ids = $this->request->post("ids");
        if ($ids) {
            $pk = $this->model->getPk();
            $adminIds = $this->getDataLimitAdminIds();
            if (is_array($adminIds)) {
                $this->model->where($this->dataLimitField, 'in', $adminIds);
            }

            $list = $this->model->where($pk, 'in', $ids)->select();
            $count = 0;

            foreach ($list as $item) {
                // 只能结算待结算状态的记录
                if ($item->state == 1) {
                    // 已退款/退款中不允许标记已结算（避免逻辑漏洞）
                    $this->assertSettlementNotRefunded((int)$item->voucher_id);
                    $item->state = 2;  // 已结算
                    $item->settlement_time = time();
                    $item->save();
                    $count++;
                }
            }

            if ($count) {
                $this->success(__('结算成功'));
            } else {
                $this->error(__('没有可结算的记录'));
            }
        }
        $this->error(__('Parameter %s can not be empty', 'ids'));
    }

    /**
     * 结算拦截：存在进行中/已成功退款则禁止结算
     *
     * @param int $voucherId
     */
    protected function assertSettlementNotRefunded(int $voucherId): void
    {
        $voucherId = (int)$voucherId;
        if ($voucherId <= 0) {
            return;
        }

        $refund = Db::name('wanlshop_voucher_refund')
            ->where('voucher_id', $voucherId)
            ->where('state', 'in', ['0', '1', '3'])
            ->where('status', 'normal')
            ->whereNull('deletetime')
            ->field('refund_no,state')
            ->order('id', 'desc')
            ->find();

        if (!$refund) {
            return;
        }

        $state = isset($refund['state']) ? (string)$refund['state'] : '';
        $stateTextMap = [
            '0' => '申请中',
            '1' => '已同意退款',
            '3' => '退款成功',
        ];
        $stateText = $stateTextMap[$state] ?? '退款处理中';
        $refundNo = isset($refund['refund_no']) ? (string)$refund['refund_no'] : '';
        $suffix = $refundNo !== '' ? "（退款单号：{$refundNo}）" : '';

        $this->error("该券存在{$stateText}退款记录{$suffix}，禁止结算");
    }
}
