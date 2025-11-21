<?php
namespace app\api\controller\wanlshop\voucher;

use app\common\controller\Api;
use app\admin\model\wanlshop\Voucher as VoucherModel;

/**
 * 核销券接口
 */
class Voucher extends Api
{
    protected $noNeedLogin = [];
    protected $noNeedRight = ['*'];

    /**
     * 我的券列表
     *
     * @ApiSummary  (获取我的核销券列表)
     * @ApiMethod   (GET)
     *
     * @param string $state 券状态(可选): 1=未使用,2=已核销,3=已过期,4=已退款
     */
    public function lists()
    {
        $this->request->filter(['strip_tags']);

        $state = $this->request->get('state');

        $where = [
            'user_id' => $this->auth->id,
            'status' => 'normal'
        ];

        if ($state && in_array($state, ['1', '2', '3', '4'])) {
            $where['state'] = $state;
        }

        // 分页查询
        $list = VoucherModel::with(['goods', 'shop', 'voucherOrder'])
            ->where($where)
            ->order('createtime desc')
            ->paginate(10);

        $list->setCollection(
            $list->getCollection()->transform(function ($voucher) {
                $data = $voucher->toArray();

                // 统一券本身的金额/数值类型
                $this->castPriceFields($data, ['supply_price', 'face_value', 'retail_price', 'coupon_price', 'discount_price', 'actual_payment', 'weight']);

                // 关联商品价格字段
                if (!empty($data['goods']) && is_array($data['goods'])) {
                    $this->castPriceFields($data['goods'], ['price', 'supply_price', 'retail_price', 'coupon_price', 'discount_price', 'actual_payment']);
                }

                // 关联订单的金额字段
                if (!empty($data['voucher_order']) && is_array($data['voucher_order'])) {
                    $this->castPriceFields($data['voucher_order'], ['supply_price', 'retail_price', 'coupon_price', 'discount_price', 'actual_payment']);
                }

                return $data;
            })
        );

        $this->success('ok', $list);
    }

    /**
     * 券详情
     *
     * @ApiSummary  (获取核销券详情)
     * @ApiMethod   (GET)
     *
     * @param int $id 券ID
     */
    public function detail()
    {
        $this->request->filter(['strip_tags']);

        $id = $this->request->get('id/d');
        if (!$id) {
            $this->error(__('参数错误'));
        }

        // 查询券并验证权限
        $voucher = VoucherModel::where([
            'id' => $id,
            'user_id' => $this->auth->id,
            'status' => 'normal'
        ])->find();

        if (!$voucher) {
            $this->error(__('券不存在'));
        }

        // 关联信息
        $voucher->state_text;
        $voucher->voucherOrder;
        $voucher->goods;
        $voucher->category;

        // 如果已核销,显示核销信息
        if ($voucher->state == 2) {
            $voucher->shop;
            $voucher->voucherVerification;
        }

        // 如果已退款,显示退款信息
        if ($voucher->state == 4) {
            $voucher->voucherRefund;
        }

        $this->success('ok', $voucher);
    }

    /**
     * 券统计
     *
     * @ApiSummary  (获取我的核销券统计信息)
     * @ApiMethod   (GET)
     *
     * 统计维度说明：
     * - total: 总核销券数（status='normal'）
     * - unused: 待核销数（state=1 且 valid_end > 当前时间）
     * - used: 已核销数（state=2）
     * - expiring_soon: 即将过期数（state=1 且 当前时间 < valid_end <= 当前时间+30天）
     * - expired: 已过期数（state=3）
     *
     * 性能优化：通过单条聚合查询使用条件求和（SUM + CASE WHEN），避免多次数据库查询。
     */
    public function statistics()
    {
        $this->request->filter(['strip_tags']);

        // 基础查询条件：当前用户 + 未软删除/正常状态
        $baseWhere = [
            'user_id' => $this->auth->id,
            'status'  => 'normal',
        ];

        // 时间边界：当前时间与未来30天（30 * 86400 秒）
        $now  = time();
        $soon = $now + 30 * 86400;

        // 使用条件聚合一次性统计各维度
        // 说明：
        // - total           = COUNT(1)
        // - unused          = state=1 AND valid_end > now
        // - used            = state=2
        // - expiring_soon   = state=1 AND valid_end > now AND valid_end <= soon
        // - expired         = state=3
        $row = VoucherModel::where($baseWhere)
            ->field("COUNT(1) AS total,
                     SUM(CASE WHEN state = 1 AND valid_end > {$now} THEN 1 ELSE 0 END) AS unused,
                     SUM(CASE WHEN state = 2 THEN 1 ELSE 0 END) AS used,
                     SUM(CASE WHEN state = 1 AND valid_end > {$now} AND valid_end <= {$soon} THEN 1 ELSE 0 END) AS expiring_soon,
                     SUM(CASE WHEN state = 3 THEN 1 ELSE 0 END) AS expired")
            ->find();

        // 取原始数据，避免触发模型的追加属性计算
        $data = $row ? $row->getData() : [];

        $this->success('ok', [
            'total'          => (int)($data['total'] ?? 0),
            'unused'         => (int)($data['unused'] ?? 0),
            'used'           => (int)($data['used'] ?? 0),
            'expiring_soon'  => (int)($data['expiring_soon'] ?? 0),
            'expired'        => (int)($data['expired'] ?? 0),
        ]);
    }

    /**
     * 将金额字段统一转换为数字类型，避免出现字符串/数字混用
     *
     * @param array $data   引用的数组数据
     * @param array $fields 需要转换的字段列表
     * @return void
     */
    private function castPriceFields(array &$data, array $fields)
    {
        foreach ($fields as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== null && $data[$field] !== '') {
                $data[$field] = (float)$data[$field];
            }
        }
    }
}
