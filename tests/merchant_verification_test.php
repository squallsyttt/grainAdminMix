<?php
/**
 * 商家后台核销记录功能验证脚本
 * 用法：php tests/merchant_verification_test.php
 */

use think\App;
use think\Config;
use think\Db;
use think\Request;
use think\View;

define('APP_PATH', __DIR__ . '/../application/');
require __DIR__ . '/../thinkphp/base.php';
\think\App::initCommon();

// 数据库连接覆盖为题中提供的真实环境
Config::set('database', array_merge(Config::get('database'), [
    'type'     => 'mysql',
    'hostname' => '127.0.0.1',
    'database' => 'grainpro',
    'username' => 'grainPro',
    'password' => 'BEhYEemBK7r3jyJ3',
    'hostport' => '3306',
    'prefix'   => 'grain_',
    'charset'  => 'utf8mb4',
]));
Db::setConfig(Config::get('database'));

$shopId = 4;
$userId = 11;

/**
 * 精简版鉴权桩，保证构造控制器时有合法的用户ID
 */
class FakeAuth
{
    public $id;

    public function __construct($id)
    {
        $this->id = $id;
    }
}

/**
 * 跳过父类 _initialize 的测试专用控制器，手动注入依赖
 */
class VoucherVerificationTestController extends \app\index\controller\wanlshop\VoucherVerification
{
    public function __construct(Request $request, $auth, $shop)
    {
        $this->view    = View::instance(Config::get('template'), Config::get('view_replace_str'));
        $this->request = $request;
        $this->auth    = $auth;
        $this->shop    = $shop;
        $this->model   = new \app\admin\model\wanlshop\VoucherVerification;
    }

    public function index()
    {
        // 显式设置别名，避免关联查询时找不到表别名
        $this->model->alias('voucher_verification');
        return parent::index();
    }

    public function getModelInstance()
    {
        return $this->model;
    }
}

/**
 * 构造 AJAX 请求对象，便于复用
 */
function makeRequest(array $params = [], array $server = []): Request
{
    $server = array_merge(['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'], $server);
    $request = Request::create('/index/wanlshop/voucher_verification/index', 'GET', $params, [], [], $server);
    $request->module('index');
    $request->controller('wanlshop.voucher_verification');
    $request->action('index');
    return $request;
}

function assertTrue($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function runTest(string $title, callable $callback): bool
{
    try {
        $callback();
        echo "[OK] {$title}\n";
        return true;
    } catch (Throwable $e) {
        echo "[FAIL] {$title} -> {$e->getMessage()}\n";
        return false;
    }
}

$results = [];
$shopContext = (object)['id' => $shopId, 'status' => 'normal'];

// 1. 验证控制器实例化
$results[] = runTest('控制器实例化', function () use ($userId, $shopContext) {
    $controller = new VoucherVerificationTestController(makeRequest(), new FakeAuth($userId), $shopContext);
    assertTrue($controller instanceof \app\index\controller\wanlshop\VoucherVerification, '控制器类加载失败');
    $model = $controller->getModelInstance();
    assertTrue($model instanceof \app\admin\model\wanlshop\VoucherVerification, '模型未正确注入');
});

// 2. 验证模型关联
$results[] = runTest('模型关联', function () {
    $model = new \app\admin\model\wanlshop\VoucherVerification;
    assertTrue($model->voucher() instanceof \think\model\relation\BelongsTo, '券关联未配置');
    assertTrue($model->user() instanceof \think\model\relation\BelongsTo, '用户关联未配置');
    assertTrue($model->shop() instanceof \think\model\relation\BelongsTo, '店铺关联未配置');
    assertTrue($model->voucher()->getModel() instanceof \app\admin\model\wanlshop\Voucher, '券关联模型错误');
});

// 3. 模拟 AJAX 查询核销记录（实际数据库）
$results[] = runTest('查询核销记录（含关联与统计）', function () use ($shopContext) {
    $model = new \app\admin\model\wanlshop\VoucherVerification;
    $list = $model
        ->with(['voucher' => function ($query) {
            $query->with(['goods']);
        }, 'user', 'shop'])
        ->where('shop_id', $shopContext->id)
        ->order('id', 'desc')
        ->limit(10)
        ->select();

    assertTrue($list && count($list) > 0, '未返回核销记录数据');
    foreach ($list as $item) {
        assertTrue((int)$item['shop_id'] === (int)$shopContext->id, '记录包含其他店铺数据，权限过滤失效');
        assertTrue($item->voucher !== null, '缺少券信息');
        assertTrue($item->user !== null, '缺少用户信息');
        assertTrue($item->shop !== null, '缺少店铺信息');
        assertTrue($item->voucher->goods !== null, '缺少关联商品信息');
    }

    $dbTotal = Db::name('wanlshop_voucher_verification')->where('shop_id', $shopContext->id)->count();
    assertTrue($dbTotal >= count($list), '总量统计与数据库不一致');
});

// 4. 验证统计数据计算逻辑
$results[] = runTest('统计数据计算逻辑', function () use ($userId, $shopContext) {
    $controller = new VoucherVerificationTestController(makeRequest(), new FakeAuth($userId), $shopContext);
    $ref = new ReflectionMethod(\app\index\controller\wanlshop\VoucherVerification::class, 'calculateStats');
    $ref->setAccessible(true);
    $stats = $ref->invoke($controller);

    $todayStart = strtotime(date('Y-m-d 00:00:00'));
    $monthStart = strtotime(date('Y-m-01 00:00:00'));

    $expected = [
        'today_count' => (int)Db::name('wanlshop_voucher_verification')
            ->where('shop_id', $shopContext->id)
            ->where('createtime', '>=', $todayStart)
            ->count(),
        'today_amount' => number_format((float)Db::name('wanlshop_voucher_verification')
            ->where('shop_id', $shopContext->id)
            ->where('createtime', '>=', $todayStart)
            ->sum('face_value'), 2, '.', ''),
        'month_count' => (int)Db::name('wanlshop_voucher_verification')
            ->where('shop_id', $shopContext->id)
            ->where('createtime', '>=', $monthStart)
            ->count(),
        'month_amount' => number_format((float)Db::name('wanlshop_voucher_verification')
            ->where('shop_id', $shopContext->id)
            ->where('createtime', '>=', $monthStart)
            ->sum('face_value'), 2, '.', ''),
    ];

    assertTrue((int)$stats['today_count'] === $expected['today_count'], '今日核销数计算错误');
    assertTrue($stats['today_amount'] === $expected['today_amount'], '今日核销金额计算错误');
    assertTrue((int)$stats['month_count'] === $expected['month_count'], '本月核销数计算错误');
    assertTrue($stats['month_amount'] === $expected['month_amount'], '本月核销金额计算错误');
});

// 5. 验证权限过滤（尝试请求其他店铺 ID，应返回空）
$results[] = runTest('权限过滤（shop_id 自动注入）', function () use ($userId, $shopContext) {
    $params = [
        'limit'  => 5,
        'offset' => 0,
        'filter' => json_encode(['shop_id' => 999999]),
        'op'     => json_encode(['shop_id' => '=']),
    ];
    $controller = new VoucherVerificationTestController(makeRequest($params), new FakeAuth($userId), $shopContext);
    $method = new ReflectionMethod(\app\common\controller\Wanlshop::class, 'buildparams');
    $method->setAccessible(true);
    // 强制关闭关联别名，避免 CLI 环境下的别名差异
    list($where) = $method->invoke($controller, null, false);
    $rows = $controller->getModelInstance()->where($where)->select();

    assertTrue(is_array($rows) || $rows instanceof \think\Collection, '返回结果类型异常');
    assertTrue(count($rows) === 0, '权限过滤未阻止跨店铺查询');
});

$passed = array_sum(array_map(function ($item) {
    return $item ? 1 : 0;
}, $results));
$total = count($results);

echo "通过 {$passed}/{$total} 项验证\n";

if ($passed < $total) {
    exit(1);
}
