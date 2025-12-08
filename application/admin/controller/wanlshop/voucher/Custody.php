<?php

namespace app\admin\controller\wanlshop\voucher;

use app\common\controller\Backend;
use app\admin\model\wanlshop\Voucher;
use app\admin\model\wanlshop\VoucherVerification;
use app\common\service\VoucherRebateService;
use app\admin\service\RebateTransferService;
use think\Db;
use think\Exception;
use think\Log;

/**
 * 核销券代管理审核
 *
 * @icon fa fa-hand-paper-o
 */
class Custody extends Backend
{
    /**
     * Voucher模型对象
     * @var Voucher
     */
    protected $model = null;
    protected $searchFields = 'voucher_no,goods_title';
    protected $relationSearch = true;

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new Voucher;

        // 代管理状态筛选
        $this->view->assign("custodyStateList", [
            '0' => '未申请',
            '1' => '申请中',
            '2' => '已通过',
            '3' => '已拒绝',
        ]);
    }

    /**
     * 列表（默认显示申请中）
     */
    public function index()
    {
        $this->relationSearch = true;
        $this->request->filter(['strip_tags', 'trim']);

        if ($this->request->isAjax()) {
            if ($this->request->request('keyField')) {
                return $this->selectpage();
            }

            list($where, $sort, $order, $offset, $limit) = $this->buildparams();

            // 默认只显示代管理相关的券（custody_state != 0）
            $custodyState = $this->request->get('custody_state', '1');

            $total = $this->model
                ->with(['user', 'voucherOrder', 'voucherRule'])
                ->where($where)
                ->where('custody_state', '<>', '0')
                ->order($sort, $order)
                ->count();

            $list = $this->model
                ->with(['user', 'voucherOrder', 'voucherRule'])
                ->where($where)
                ->where('custody_state', '<>', '0')
                ->order($sort, $order)
                ->limit($offset, $limit)
                ->select();

            foreach ($list as $row) {
                if ($row->user) {
                    $row->getRelation('user')->visible(['nickname', 'mobile', 'avatar']);
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
            ->with(['user', 'voucherOrder', 'voucherRule', 'goods'])
            ->find($ids);

        if (!$row) {
            $this->error(__('No Results were found'));
        }

        if ($this->request->isAjax()) {
            $this->success('ok', $row);
        }

        $this->view->assign("row", $row);
        return $this->view->fetch();
    }

    /**
     * 审核通过
     */
    public function approve()
    {
        $ids = $this->request->post('ids');
        if (!$ids) {
            $this->error(__('Parameter %s can not be empty', 'ids'));
        }

        $ids = is_array($ids) ? $ids : explode(',', $ids);

        Db::startTrans();
        try {
            $successCount = 0;
            $failedCount = 0;
            $errors = [];

            foreach ($ids as $id) {
                try {
                    $this->processApprove((int)$id);
                    $successCount++;
                } catch (Exception $e) {
                    $failedCount++;
                    $errors[] = "券ID {$id}: " . $e->getMessage();
                }
            }

            Db::commit();

            if ($failedCount > 0) {
                $this->error("成功 {$successCount} 条，失败 {$failedCount} 条: " . implode('; ', $errors));
            }

            $this->success("审核通过 {$successCount} 条");

        } catch (Exception $e) {
            Db::rollback();
            $this->error('审核失败：' . $e->getMessage());
        }
    }

    /**
     * 审核拒绝
     */
    public function reject()
    {
        $ids = $this->request->post('ids');
        $refuseReason = $this->request->post('refuse_reason', '');

        if (!$ids) {
            $this->error(__('Parameter %s can not be empty', 'ids'));
        }

        if (!$refuseReason) {
            $this->error(__('请填写拒绝理由'));
        }

        $ids = is_array($ids) ? $ids : explode(',', $ids);

        Db::startTrans();
        try {
            $successCount = 0;
            $failedCount = 0;
            $errors = [];

            foreach ($ids as $id) {
                try {
                    $this->processReject((int)$id, $refuseReason);
                    $successCount++;
                } catch (Exception $e) {
                    $failedCount++;
                    $errors[] = "券ID {$id}: " . $e->getMessage();
                }
            }

            Db::commit();

            if ($failedCount > 0) {
                $this->error("成功 {$successCount} 条，失败 {$failedCount} 条: " . implode('; ', $errors));
            }

            $this->success("审核拒绝 {$successCount} 条");

        } catch (Exception $e) {
            Db::rollback();
            $this->error('审核失败：' . $e->getMessage());
        }
    }

    /**
     * 统计数据
     */
    public function statistics()
    {
        $stats = Db::name('wanlshop_voucher')
            ->where('status', 'normal')
            ->field("
                SUM(CASE WHEN custody_state = '1' THEN 1 ELSE 0 END) AS pending,
                SUM(CASE WHEN custody_state = '2' THEN 1 ELSE 0 END) AS approved,
                SUM(CASE WHEN custody_state = '3' THEN 1 ELSE 0 END) AS rejected
            ")
            ->find();

        $this->success('ok', [
            'pending' => (int)($stats['pending'] ?? 0),
            'approved' => (int)($stats['approved'] ?? 0),
            'rejected' => (int)($stats['rejected'] ?? 0),
        ]);
    }

    /**
     * 处理单个审核通过
     *
     * @param int $voucherId
     * @throws Exception
     */
    protected function processApprove($voucherId)
    {
        // 查询券并加锁
        $voucher = Voucher::where('id', $voucherId)
            ->lock(true)
            ->find();

        if (!$voucher) {
            throw new Exception('券不存在');
        }

        // 验证券状态
        if ($voucher->state != 1) {
            throw new Exception('券状态不是未使用');
        }

        // 验证代管理状态
        if ($voucher->custody_state != '1') {
            throw new Exception('券不是申请中状态');
        }

        $now = time();

        // 获取平台店铺商品信息（shop_id=1）
        $rebateService = new VoucherRebateService();
        $platformGoodsInfo = $rebateService->getShopGoodsInfo(
            1,  // 平台店铺 ID
            $voucher->category_id,
            $voucher->sku_difference
        );
        $platformSupplyPrice = $platformGoodsInfo['sku_price'];

        // 更新券状态
        $voucher->state = 2;  // 已核销
        $voucher->custody_state = '2';  // 已通过
        $voucher->custody_audit_time = $now;
        $voucher->custody_admin_id = $this->auth->id;
        $voucher->verifytime = $now;
        $voucher->shop_id = 1;  // 平台店铺
        $voucher->shop_name = '平台代管理';
        $voucher->supply_price = $platformSupplyPrice;
        $voucher->verify_user_id = $this->auth->id;
        $voucher->save();

        // 创建虚拟核销记录
        $verification = new VoucherVerification();
        $verification->voucher_id = $voucher->id;
        $verification->voucher_no = $voucher->voucher_no;
        $verification->user_id = $voucher->user_id;
        $verification->shop_id = 1;  // 平台店铺
        $verification->shop_name = '平台代管理';
        $verification->verify_user_id = $this->auth->id;
        $verification->shop_goods_id = $platformGoodsInfo['goods_id'];
        $verification->shop_goods_title = $platformGoodsInfo['goods_title'];
        $verification->supply_price = $platformSupplyPrice;
        $verification->face_value = $voucher->face_value;
        $verification->verify_method = 'custody';  // 代管理核销方式
        $verification->createtime = $now;
        $verification->save();

        // 创建返利记录（使用代管理返利类型）
        $rebate = $rebateService->createRebateRecord($voucher, $verification, $now, 1, $platformGoodsInfo, 'custody');

        // 自动发起打款（代管理不需要等待7天）
        try {
            $transferService = new RebateTransferService();
            $transferResult = $transferService->transferCustody((int)$rebate->id);

            if (!$transferResult['success']) {
                Log::warning("代管理返利打款发起失败[rebate_id={$rebate->id}]: " . ($transferResult['message'] ?? '未知错误'));
                // 打款失败不影响审核通过流程，记录日志即可
            }
        } catch (\Exception $e) {
            Log::error("代管理返利打款异常[rebate_id={$rebate->id}]: " . $e->getMessage());
            // 打款异常不影响审核通过流程
        }
    }

    /**
     * 处理单个审核拒绝
     *
     * @param int $voucherId
     * @param string $refuseReason
     * @throws Exception
     */
    protected function processReject($voucherId, $refuseReason)
    {
        // 查询券并加锁
        $voucher = Voucher::where('id', $voucherId)
            ->lock(true)
            ->find();

        if (!$voucher) {
            throw new Exception('券不存在');
        }

        // 验证代管理状态
        if ($voucher->custody_state != '1') {
            throw new Exception('券不是申请中状态');
        }

        $now = time();

        // 更新代管理状态为已拒绝
        $voucher->custody_state = '3';  // 已拒绝
        $voucher->custody_audit_time = $now;
        $voucher->custody_admin_id = $this->auth->id;
        $voucher->custody_refuse_reason = $refuseReason;
        // state 保持不变（用户可继续核销或重新申请）
        $voucher->save();
    }
}
