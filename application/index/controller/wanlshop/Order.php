<?php
namespace app\index\controller\wanlshop;

use app\common\controller\Wanlshop;

/**
 * 核销记录管理（商家后台）
 *
 * @icon fa fa-check-square
 */
class Order extends Wanlshop
{
    protected $noNeedLogin = '';
    protected $noNeedRight = '*';
    protected $searchFields = 'voucher_no,shop_name,shop_goods_title';
    /**
     * VoucherVerification模型对象
     * @var \app\admin\model\wanlshop\VoucherVerification
     */
    protected $model = null;

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new \app\admin\model\wanlshop\VoucherVerification;
    }

    /**
     * 查看核销记录列表
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

            // 统计数据计算（在 buildparams 之前，避免模型被污染）
            $stats = $this->calculateStats();

            list($where, $sort, $order, $offset, $limit) = $this->buildparams();

            $total = $this->model
                ->with(['voucher' => function($query){ $query->with(['goods']); }, 'user', 'shop'])
                ->where($where)
                ->count();

            $list = $this->model
                ->with(['voucher' => function($query){ $query->with(['goods']); }, 'user', 'shop'])
                ->where($where)
                ->order($sort, $order)
                ->limit($offset, $limit)
                ->select();

            foreach ($list as $row) {
                $row->getRelation('user')->visible(['id', 'username', 'nickname', 'avatar']);
                $row->getRelation('shop')->visible(['id', 'shopname']);
                if ($row->voucher) {
                    $row->getRelation('voucher')->visible(['id', 'voucher_no', 'goods_title', 'state', 'goods']);
                }
                // 统一商品展示字段，优先使用核销记录自身的商品标题
                $row['shop_goods_title'] = $row['shop_goods_title'] ?: ($row->voucher ? $row->voucher->goods_title : '');
                // 商品图片取券商品图，核销记录侧没有图片字段
                $row['product_image'] = ($row->voucher && $row->voucher->goods && isset($row->voucher->goods->image)) ? $row->voucher->goods->image : '';
            }

            $list = collection($list)->toArray();
            $result = array("total" => $total, "rows" => $list, "stats" => $stats);

            return json($result);
        }
        return $this->view->fetch();
    }

    /**
     * 详情
     */
    public function detail($id = null, $order_no = null)
    {
        // 兼容旧参数名，支持按券号查询
        $voucherNo = $this->request->param('voucher_no', $order_no, 'trim');
        $where = $voucherNo ? ['voucher_no' => $voucherNo] : ['id' => $id];

        $row = $this->model
            ->with(['voucher' => function($query){ $query->with(['goods']); }, 'user', 'shop'])
            ->where($where)
            ->find();

        if (!$row) {
            $this->error(__('No Results were found'));
        }

        // 判断权限：商家只能查看自己店铺的核销记录
        if ($row['shop_id'] != $this->shop->id) {
            $this->error(__('You have no permission'));
        }

        // 兼容前端模板所需的城市信息
        $goods = $row->voucher && $row->voucher->goods ? $row->voucher->goods : null;
        $row['region_city_name'] = $goods && isset($goods['region_city_name']) ? $goods['region_city_name'] : '';

        $this->view->assign("row", $row);
        return $this->view->fetch();
    }

    /**
     * 计算统计数据
     * @return array
     */
    private function calculateStats()
    {
        // 获取今日起始时间戳（0点）
        $todayStart = strtotime(date('Y-m-d 00:00:00'));
        // 获取本月起始时间戳（1日0点）
        $monthStart = strtotime(date('Y-m-01 00:00:00'));

        // 使用新模型实例避免 buildparams 污染别名
        $statsModel = new \app\admin\model\wanlshop\VoucherVerification;

        // 今日核销数
        $todayCount = $statsModel
            ->where('shop_id', $this->shop->id)
            ->where('createtime', '>=', $todayStart)
            ->count();

        // 今日核销金额（使用供货价字段）
        $todayAmount = (new \app\admin\model\wanlshop\VoucherVerification)
            ->where('shop_id', $this->shop->id)
            ->where('createtime', '>=', $todayStart)
            ->sum('supply_price');

        // 本月核销数
        $monthCount = (new \app\admin\model\wanlshop\VoucherVerification)
            ->where('shop_id', $this->shop->id)
            ->where('createtime', '>=', $monthStart)
            ->count();

        // 本月核销金额（供货价汇总）
        $monthAmount = (new \app\admin\model\wanlshop\VoucherVerification)
            ->where('shop_id', $this->shop->id)
            ->where('createtime', '>=', $monthStart)
            ->sum('supply_price');

        return [
            'today_count' => $todayCount,
            'today_amount' => number_format($todayAmount ?: 0, 2, '.', ''),
            'month_count' => $monthCount,
            'month_amount' => number_format($monthAmount ?: 0, 2, '.', '')
        ];
    }
}
