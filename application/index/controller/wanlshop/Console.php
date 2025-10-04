<?php

namespace app\index\controller\wanlshop;

use app\common\controller\Wanlshop;

/**
 * 主页
 * @internal
 */
class Console extends Wanlshop
{
    protected $noNeedLogin = '';
    protected $noNeedRight = '*';
    
    public function _initialize()
    {
        parent::_initialize();
    }
    
    public function index()
    {
		$shop_id = $this->shop->id;

        $this->view->assign([
            'totaluser'        => model('app\index\model\wanlshop\Goods')->where('shop_id', $shop_id)->count(), //商品总数
            'totalorder'       => model('app\index\model\wanlshop\Order')->where('shop_id', $shop_id)->where('state', 6)->count(), // 总核销数
            'totalorderamount' => model('app\index\model\wanlshop\Pay')->where('shop_id', $shop_id)->sum('price'), //总金额
        ]);
        return $this->view->fetch();
    }
}


