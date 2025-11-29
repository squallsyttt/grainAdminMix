<?php
// 2020年2月17日22:04:21
namespace app\index\controller\wanlshop;

use app\common\controller\Wanlshop;
use think\Db;
use think\Exception;
use think\exception\PDOException;
use think\exception\ValidateException;
use fast\Tree;

/**
 * 产品管理
 * @internal
 */
class Goods extends Wanlshop
{
    protected $noNeedLogin = '';
    protected $noNeedRight = '*';
    protected $searchFields = 'title';
    
    protected $model = null;
    
    public function _initialize()
    {
        parent::_initialize();
        $this->model = new \app\index\model\wanlshop\Goods;
        // 类目
        $tree = Tree::instance();
		// 1.0.2升级 过滤隐藏
        $tree->init(model('app\index\model\wanlshop\Category')->where(['type' => 'goods', 'isnav' => 1])->field('id,pid,name')->order('weigh asc,id asc')->select());
        $this->assignconfig('channelList', $tree->getTreeArray(0));
        $this->view->assign("flagList", $this->model->getFlagList());
        $this->view->assign("stockList", $this->model->getStockList());
        $this->view->assign("specsList", $this->model->getSpecsList());
        $this->view->assign("distributionList", $this->model->getDistributionList());
        $this->view->assign("activityList", $this->model->getActivityList());
        $this->view->assign("statusList", $this->model->getStatusList());
        $this->assignconfig('isPlatformShop', $this->shop ? $this->shop->id == 1 : false);
        $this->assignconfig('shopCityName', $this->shop ? $this->shop->city : '');
    }
    
    /**
     * 查看
     */
    public function index()
    {
        //当前是否为关联查询
        $this->relationSearch = true;
        //设置过滤方法
        $this->request->filter(['strip_tags']);
        if ($this->request->isAjax()) {
            //如果发送的来源是Selectpage，则转发到Selectpage
            if ($this->request->request('keyField')) {
                return $this->selectpage();
            }

            // 手动解析查询参数，避免 buildparams 闭包引用 $this->model 导致的污染问题
            $sort = $this->request->get("sort", "weigh");
            $order = $this->request->get("order", "DESC");
            $offset = $this->request->get("offset", 0);
            $limit = $this->request->get("limit", 10);
            $filter = $this->request->get("filter", '');
            $op = $this->request->get("op", '', 'trim');
            $filter = (array)json_decode($filter, true);
            $op = (array)json_decode($op, true);
            $filter = $filter ? $filter : [];

            // 构建 where 条件数组（不使用闭包）
            $whereArr = [];
            $aliasName = 'goods.';

            // shop_id 限制
            $whereArr[] = [$aliasName . 'shop_id', '=', $this->shop->id];

            // 解析筛选条件
            foreach ($filter as $k => $v) {
                $sym = isset($op[$k]) ? $op[$k] : '=';
                if (stripos($k, ".") === false) {
                    $k = $aliasName . $k;
                }
                $v = !is_array($v) ? trim($v) : $v;
                $sym = strtoupper(isset($op[$k]) ? $op[$k] : $sym);
                switch ($sym) {
                    case '=':
                    case '<>':
                        $whereArr[] = [$k, $sym, (string)$v];
                        break;
                    case 'LIKE':
                    case 'NOT LIKE':
                    case 'LIKE %...%':
                    case 'NOT LIKE %...%':
                        $whereArr[] = [$k, 'LIKE', "%{$v}%"];
                        break;
                    case '>':
                    case '>=':
                    case '<':
                    case '<=':
                        $whereArr[] = [$k, $sym, intval($v)];
                        break;
                    case 'IN':
                    case 'IN(...)':
                    case 'NOT IN':
                    case 'NOT IN(...)':
                        $whereArr[] = [$k, str_replace('(...)', '', $sym), is_array($v) ? $v : explode(',', $v)];
                        break;
                    case 'BETWEEN':
                    case 'NOT BETWEEN':
                        $arr = array_slice(explode(',', $v), 0, 2);
                        if (stripos($v, ',') === false || !array_filter($arr)) {
                            continue 2;
                        }
                        if ($arr[0] === '') {
                            $sym = $sym == 'BETWEEN' ? '<=' : '>';
                            $arr = $arr[1];
                        } elseif ($arr[1] === '') {
                            $sym = $sym == 'BETWEEN' ? '>=' : '<';
                            $arr = $arr[0];
                        }
                        $whereArr[] = [$k, $sym, $arr];
                        break;
                }
            }

            // 处理排序字段别名
            $sortArr = explode(',', $sort);
            foreach ($sortArr as $index => &$item) {
                $item = stripos($item, ".") === false ? $aliasName . trim($item) : $item;
            }
            unset($item);
            $sort = implode(',', $sortArr);

            // 商品列表已按 shop_id 过滤，无需额外城市过滤
            $shopCityFilter = null;

            // 创建独立的 where 闭包（不引用外部模型）
            $whereClosure = function ($query) use ($whereArr) {
                foreach ($whereArr as $v) {
                    if (is_array($v)) {
                        call_user_func_array([$query, 'where'], $v);
                    } else {
                        $query->where($v);
                    }
                }
            };

            // 计数查询（不需要关联，直接查主表）
            $total = \think\Db::name('wanlshop_goods')
                ->alias('goods')
                ->where($whereClosure)
                ->count();

            // 列表查询（需要关联 category）
            $listModel = new \app\index\model\wanlshop\Goods;
            $list = $listModel
                ->alias('goods')
                ->with(['category'])
                ->where($whereClosure)
                ->order($sort, $order)
                ->limit($offset, $limit)
                ->select();
            foreach ($list as $row) {
                if (isset($row->category)) {
                    $row->getRelation('category')->visible(['name']);
                }
            }
            $list = collection($list)->toArray();
            $result = array("total" => $total, "rows" => $list);

            return json($result);
        }
        return $this->view->fetch();
    }
    
    /**
     * 选择链接
     */
    public function select()
    {
        if ($this->request->isAjax()) {
            return $this->index();
        }
        return $this->view->fetch();
    }
    
    /**
     * 仓库中的商品
     */
    public function stock()
    {
        return $this->view->fetch('wanlshop/goods/index');
    }
    
    /**
     * 添加
     */
	public function add()
	{
		//设置过滤方法
		$this->request->filter(['']);
	    if ($this->request->isPost()) {
	        $params = $this->request->post("row/a");
	        if ($params) {
				// 判断产品属性是否存在
				empty($params['spuItem'])?$this->error(__('请完善：销售信息 - 产品属性')):'';
	            $result = false;
	            Db::startTrans();
	            try {
	                $spudata = isset($params['spu'])?$params['spu']:$this->error(__('请填写销售信息-产品属性'));
	                $spuItem = isset($params['spuItem'])?$params['spuItem']:$this->error(__('请填写销售信息-产品属性-产品规格'));
	                // 获取自增ID
	                $this->model->shop_id = $this->shop->id;
	                $this->model->category_id = $params['category_id'];
					if(isset($params['attribute'])){
						$this->model->category_attribute = json_encode($params['attribute'], JSON_UNESCAPED_UNICODE);
					}
	                $this->model->title = $params['title'];
	                $this->model->image = $params['image'];
                $this->model->images = $params['images'];
                $this->model->description = $params['description'];
                $this->model->stock = $params['stock'];
                $this->model->status = $params['status'];
                // 平台店铺可选择城市，其他店铺绑定自身城市
                if ($this->shop->id == 1) {
                    $regionCode = isset($params['region_city_code']) ? $params['region_city_code'] : '';
                    $regionName = isset($params['region_city_name']) ? $params['region_city_name'] : '';
                    if (!$regionCode || !$regionName) {
                        $this->error(__('请选择发布的地级市'));
                    }
                    $this->model->region_city_code = $regionCode;
                    $this->model->region_city_name = $regionName;
                } else {
                    $this->model->region_city_code = null;
                    $this->model->region_city_name = $this->shop->city;
                }
                $this->model->content = $params['content'];
                $this->model->price = min($params['price']);
                if($this->model->save()){
                	$result = true;
                }
					// 写入SPU
					$spu = [];
					foreach (explode(",", $spudata) as $key => $value) {
					    $spu[] = [
					        'goods_id'	=> $this->model->id,
					        'name'		=> $value,
					        'item'		=> $spuItem[$key]
					    ];
					}
					if(!model('app\index\model\wanlshop\GoodsSpu')->allowField(true)->saveAll($spu)){
						$result == false;
					}
					// 写入SKU
					$sku = [];
					foreach ($params['sku']  as $key => $value) {
					    $sku[] = [
					        'goods_id' 		=> $this->model->id,
							'thumbnail' 	=> isset($params['thumbnail']) ? $params['thumbnail'][$key] : false, // 1.0.8升级
					        'difference' 	=> $value,
					        'market_price' 	=> $params['price'][$key],
					        'price' 		=> $params['price'][$key],
					        'stock' 		=> $params['stocks'][$key],
					        'weigh' 		=> $params['weigh'][$key]!=''?$params['weigh'][$key] : 0,
					        'sn' 			=> $params['sn'][$key]!=''?$params['sn'][$key] : 'wanl_'.time()
					    ];
					}
					if(!model('app\index\model\wanlshop\GoodsSku')->allowField(true)->saveAll($sku)){
						$result == false;
					}
	                Db::commit();
	            } catch (ValidateException $e) {
	                Db::rollback();
	                $this->error($e->getMessage());
	            } catch (PDOException $e) {
	                Db::rollback();
	                $this->error($e->getMessage());
	            } catch (Exception $e) {
	                Db::rollback();
	                $this->error($e->getMessage());
	            }
	            if ($result !== false) {
	                $this->success();
	            } else {
	                $this->error(__('No rows were inserted'));
	            }
	        }
	        $this->error(__('Parameter %s can not be empty', ''));
	    }
	    $row = [];
	    $shop_id = $this->shop->id;
		// 打开方式
		$this->assignconfig("isdialog", IS_DIALOG);
		$this->assignconfig('regionCityCode', $this->shop->id == 1 ? '' : '');
		$this->assignconfig('regionCityName', $this->shop->id == 1 ? '' : $this->shop->city);
		$this->view->assign("row", $row);
		return $this->view->fetch();
	}
    
    /**
     * 编辑
     */
    public function edit($ids = null)
    {
		//设置过滤方法
		$this->request->filter(['']);
        $row = $this->model->get($ids);
        if (!$row) {
            $this->error(__('No Results were found'));
        }
        if ($row['shop_id'] != $this->shop->id) {
            $this->error(__('You have no permission'));
        }
		// 查询SKU
		$skuItem = model('app\index\model\wanlshop\GoodsSku')
			->where(['goods_id' => $ids, 'state' => 0])
			->field('id,thumbnail,difference,price,market_price,stock,weigh,sn,sales,state')
			->select();
        if ($this->request->isPost()) {
            $params = $this->request->post("row/a");
            if ($params) {
				// 判断产品属性是否存在
				empty($params['spuItem'])?$this->error(__('请完善：销售信息 - 产品属性')):'';
                $result = false;
                Db::startTrans();
                try {
					$spudata = isset($params['spu'])?$params['spu']:$this->error(__('请填写销售信息-产品属性'));
					$spuItem = isset($params['spuItem'])?$params['spuItem']:$this->error(__('请填写销售信息-产品属性-产品规格'));
					// 写入表单
					$data = $params;
					if(isset($data['attribute'])){
						$data['category_attribute'] = json_encode($data['attribute'], JSON_UNESCAPED_UNICODE);
					}
					if ($this->shop->id == 1) {
						$regionCode = isset($params['region_city_code']) ? $params['region_city_code'] : '';
						$regionName = isset($params['region_city_name']) ? $params['region_city_name'] : '';
						if (!$regionCode || !$regionName) {
							$this->error(__('请选择发布的地级市'));
						}
						$data['region_city_code'] = $regionCode;
						$data['region_city_name'] = $regionName;
					} else {
						$data['region_city_code'] = $row['region_city_code'];
						$data['region_city_name'] = $this->shop->city;
					}
					$data['price'] = min($data['price']);
                    $result = $row->allowField(true)->save($data);
					// 删除原来数据,重新写入SPU
					model('app\index\model\wanlshop\GoodsSpu')
						->where('goods_id','in',$ids)
						->delete();
					$spu = [];
					foreach (explode(",", $spudata) as $key => $value) {
					    $spu[] = [
					        'goods_id' => $ids,
					        'name' => $value,
					        'item' => $spuItem[$key]
					    ];
					}
					if(!model('app\index\model\wanlshop\GoodsSpu')->allowField(true)->saveAll($spu)){
						$result == false;
					}
					//标记旧版SKU数据
					$oldsku = [];
					foreach ($skuItem as $value) {
						$oldsku[] = [
							'id' => $value['id'],
							'state' => 1
						];
					}
					if(!model('app\index\model\wanlshop\GoodsSku')->allowField(true)->saveAll($oldsku)){
						$result == false;
					}
					// 写入SKU
					$sku = [];
					foreach ($params['sku'] as $key => $value) {
					    $sku[] = [
					        'goods_id' => $ids,
							'thumbnail' => isset($params['thumbnail']) ? $params['thumbnail'][$key] : false, // 1.0.8升级
					        'difference' => $value,
					        'market_price' => $params['price'][$key],
					        'price' => $params['price'][$key],
					        'stock' => $params['stocks'][$key],
					        'weigh' => $params['weigh'][$key]!=''?$params['weigh'][$key] : 0,
					        'sn' => $params['sn'][$key]!=''?$params['sn'][$key] : 'wanl_'.time()
					    ];
					}
					if(!model('app\index\model\wanlshop\GoodsSku')->allowField(true)->saveAll($sku)){
						$result == false;
					}
                    Db::commit();
                } catch (ValidateException $e) {
                    Db::rollback();
                    $this->error($e->getMessage());
                } catch (PDOException $e) {
                    Db::rollback();
                    $this->error($e->getMessage());
                } catch (Exception $e) {
                    Db::rollback();
                    $this->error($e->getMessage());
                }
                if ($result !== false) {
                    $this->success();
                } else {
                    $this->error(__('No rows were updated'));
                }
            }
            $this->error(__('Parameter %s can not be empty', ''));
        }
		$spuData = model('app\index\model\wanlshop\GoodsSpu')->all(['goods_id' => $ids]);
		$suk = [];
		foreach ($skuItem as $vo) {
		    $suk[] = explode(",", $vo['difference']);
		}
		$spu = [];
		foreach ($spuData as $vo) {
		    $spu[] = $vo['name'];
		}
		$spuItem = [];
		foreach ($spuData as $vo) {
		    $spuItem[] = explode(",", $vo['item']);
		}
		$skulist = [];
		foreach ($skuItem as $vo) {
		    $skulist[$vo['difference']] = $vo;
		}
        $this->assignconfig('spu', $spu);
        $this->assignconfig('spuItem', $spuItem);
        $this->assignconfig('sku', $suk);
        $this->assignconfig('skuItem', $skulist);
        $this->assignconfig('categoryId', $row['category_id']);
        $this->assignconfig('attribute', json_decode($row['category_attribute']));
        $this->view->assign("row", $row);
        $this->assignconfig('regionCityCode', $row['region_city_code'] ? $row['region_city_code'] : '');
        $this->assignconfig('regionCityName', $row['region_city_name'] ? $row['region_city_name'] : $this->shop->city);
        return $this->view->fetch();
    }
    
    /**
     * 添加类目属性
     */
	public function attribute()
	{
	    if ($this->request->isAjax()) {
	        $id = $this->request->request("id");
			// 1.0.8升级  获取父级类目属性
			$tree = Tree::instance();
			$tree->init(collection(model('app\index\model\wanlshop\Category')->select())->toArray(), 'pid');
	        $list  = model('app\index\model\wanlshop\Attribute')
	            ->where('category_id', 'in', $tree->getParentsIds($id, true))
				->where('status','normal')
	            ->select();
	        $this->success('查询成功', '', $list);
	    }
	    $this->error(__('Parameter %s can not be empty', ''));
	}
    
    /**
     * 回收站
     */
    public function recyclebin()
    {
        //设置过滤方法
        $this->request->filter(['strip_tags']);
        if ($this->request->isAjax()) {
            list($where, $sort, $order, $offset, $limit) = $this->buildparams();
            $total = $this->model
                ->onlyTrashed()
                ->where($where)
                ->order($sort, $order)
                ->count();
    
            $list = $this->model
                ->onlyTrashed()
                ->where($where)
                ->order($sort, $order)
                ->limit($offset, $limit)
                ->select();
    
            $result = array("total" => $total, "rows" => $list);
    
            return json($result);
        }
        return $this->view->fetch();
    }
    
    /**
     * 删除
     */
    public function del($ids = "")
    {
        if ($ids) {
            $pk = $this->model->getPk();
            $this->model->where('shop_id', '=', $this->shop->id);
            $list = $this->model->where($pk, 'in', $ids)->select();
    
            $count = 0;
            Db::startTrans();
            try {
                foreach ($list as $k => $v) {
                    $count += $v->delete();
                }
                Db::commit();
            } catch (PDOException $e) {
                Db::rollback();
                $this->error($e->getMessage());
            } catch (Exception $e) {
                Db::rollback();
                $this->error($e->getMessage());
            }
            if ($count) {
                $this->success();
            } else {
                $this->error(__('No rows were deleted'));
            }
        }
        $this->error(__('Parameter %s can not be empty', 'ids'));
    }
    
    /**
     * 真实删除
     */
    public function destroy($ids = "")
    {
        $pk = $this->model->getPk();
        $this->model->where('shop_id', '=', $this->shop->id);
        if ($ids) {
            $this->model->where($pk, 'in', $ids);
        }
        $count = 0;
        Db::startTrans();
        try {
            $list = $this->model->onlyTrashed()->select();
            foreach ($list as $k => $v) {
                $count += $v->delete(true);
            }
            Db::commit();
        } catch (PDOException $e) {
            Db::rollback();
            $this->error($e->getMessage());
        } catch (Exception $e) {
            Db::rollback();
            $this->error($e->getMessage());
        }
        if ($count) {
            $this->success();
        } else {
            $this->error(__('No rows were deleted'));
        }
        $this->error(__('Parameter %s can not be empty', 'ids'));
    }
    
    /**
     * 还原
     */
    public function restore($ids = "")
    {
        $pk = $this->model->getPk();
        $this->model->where('shop_id', '=', $this->shop->id);
        if ($ids) {
            $this->model->where($pk, 'in', $ids);
        }
        $count = 0;
        Db::startTrans();
        try {
            $list = $this->model->onlyTrashed()->select();
            foreach ($list as $index => $item) {
                $count += $item->restore();
            }
            Db::commit();
        } catch (PDOException $e) {
            Db::rollback();
            $this->error($e->getMessage());
        } catch (Exception $e) {
            Db::rollback();
            $this->error($e->getMessage());
        }
        if ($count) {
            $this->success();
        }
        $this->error(__('No rows were updated'));
    }
    
    /**
     * 批量更新
     */
    public function multi($ids = "")
    {
        $ids = $ids ? $ids : $this->request->param("ids");
        if ($ids) {
            if ($this->request->has('params')) {
                parse_str($this->request->post("params"), $values);
                // $values = $this->auth->isSuperAdmin() ? $values : array_intersect_key($values, array_flip(is_array($this->multiFields) ? $this->multiFields : explode(',', $this->multiFields)));
                if ($values) {
                    $this->model->where('shop_id', '=', $this->shop->id);
                    $count = 0;
                    Db::startTrans();
                    try {
                        $list = $this->model->where($this->model->getPk(), 'in', $ids)->select();
                        foreach ($list as $index => $item) {
                            $count += $item->allowField(true)->isUpdate(true)->save($values);
                        }
                        Db::commit();
                    } catch (PDOException $e) {
                        Db::rollback();
                        $this->error($e->getMessage());
                    } catch (Exception $e) {
                        Db::rollback();
                        $this->error($e->getMessage());
                    }
                    if ($count) {
                        $this->success();
                    } else {
                        $this->error(__('No rows were updated'));
                    }
                } else {
                    $this->error(__('You have no permission'));
                }
            }
        }
        $this->error(__('Parameter %s can not be empty', 'ids'));
    }

    /**
     * 同步规范商品
     */
    public function syncStandardGoods()
    {
        if ($this->request->isAjax()) {
            $currentShopId = $this->shop->id;

            // 检查当前商家是否已有商品
            $existingCount = $this->model
                ->where('shop_id', $currentShopId)
                ->where('deletetime', 'null')
                ->count();

            $message = $existingCount > 0
                ? '库存已经有商品，现在开始同步最新的规范商品到后台'
                : '开始同步规范商品';

            // 获取当前店铺的配送地级市
            $deliveryCityName = $this->shop->delivery_city_name;
            if (empty($deliveryCityName)) {
                $this->error('当前店铺未设置配送地级市，无法获取规范商品');
            }

            try {
                // 获取规范商品（shop_id=1，且配送城市匹配）
                $standardGoods = $this->model
                    ->where('shop_id', 1)
                    ->where('region_city_name', $deliveryCityName)
                    ->where('deletetime', 'null')
                    ->select();

                if (empty($standardGoods)) {
                    $this->error('当前城市暂无规范商品可同步');
                }

                $insertCount = 0;
                $currentDateTime = date('Y-m-d H:i:s');

                Db::startTrans();
                try {
                    foreach ($standardGoods as $goods) {
                        // 源商品ID（用于复制关联SPU/SKU）
                        $sourceGoodsId = is_object($goods) ? (isset($goods['id']) ? $goods['id'] : null) : (isset($goods['id']) ? $goods['id'] : null);

                        // 复制商品数据
                        $newGoods = is_object($goods) ? $goods->toArray() : $goods;
                        unset($newGoods['id']); // 移除主键，让数据库自增

                        // 修改必要字段
                        $newGoods['shop_id'] = $currentShopId;
                        $newGoods['status'] = 'hidden';
                        $newGoods['title'] = $newGoods['title'] . ' [' . $currentDateTime . ']';
                        $newGoods['createtime'] = time();
                        $newGoods['updatetime'] = time();
                        $newGoods['deletetime'] = null;

                        // 插入新商品
                        $newGoodsModel = new \app\index\model\wanlshop\Goods;
                        if ($newGoodsModel->allowField(true)->save($newGoods)) {
                            $insertCount++;

                            // 复制SPU
                            if ($sourceGoodsId) {
                                $spuList = (new \app\index\model\wanlshop\GoodsSpu())
                                    ->where('goods_id', $sourceGoodsId)
                                    ->where('deletetime', 'null')
                                    ->select();
                                if ($spuList) {
                                    $spuRows = [];
                                    $nowTs = time();
                                    foreach ($spuList as $spu) {
                                        $spuRows[] = [
                                            'goods_id'   => $newGoodsModel->id,
                                            'name'       => $spu['name'],
                                            'item'       => $spu['item'],
                                            'createtime' => $nowTs,
                                            'updatetime' => $nowTs,
                                            'deletetime' => null,
                                        ];
                                    }
                                    if ($spuRows) {
                                        (new \app\index\model\wanlshop\GoodsSpu())->allowField(true)->saveAll($spuRows);
                                    }
                                }

                                // 复制SKU（仅复制有效 state=0 的规格）
                                $skuList = (new \app\index\model\wanlshop\GoodsSku())
                                    ->where('goods_id', $sourceGoodsId)
                                    ->where('deletetime', 'null')
                                    ->where('state', 0)
                                    ->select();
                                if ($skuList) {
                                    $skuRows = [];
                                    $nowTs = time();
                                    foreach ($skuList as $sku) {
                                        $skuRows[] = [
                                            'goods_id'     => $newGoodsModel->id,
                                            'thumbnail'    => isset($sku['thumbnail']) ? $sku['thumbnail'] : null,
                                            'difference'   => $sku['difference'],
                                            'market_price' => $sku['market_price'],
                                            'price'        => $sku['price'],
                                            'stock'        => $sku['stock'],
                                            'weigh'        => isset($sku['weigh']) ? $sku['weigh'] : 0,
                                            'sn'           => isset($sku['sn']) ? $sku['sn'] : ('wanl_' . $nowTs),
                                            'state'        => 0,
                                            'createtime'   => $nowTs,
                                            'updatetime'   => $nowTs,
                                            'deletetime'   => null,
                                        ];
                                    }
                                    if ($skuRows) {
                                        (new \app\index\model\wanlshop\GoodsSku())->allowField(true)->saveAll($skuRows);
                                    }
                                }
                            }
                        }
                    }

                    Db::commit();
                    $this->success($message . '，成功同步 ' . $insertCount . ' 个商品');
                } catch (\Exception $e) {
                    // 不拦截框架用于返回响应的异常
                    if ($e instanceof \think\exception\HttpResponseException) {
                        throw $e;
                    }
                    Db::rollback();
                    $errMsg = '同步失败：' . ($e->getMessage() ?: get_class($e));
                    $errData = [
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                    ];
                    // 调试模式下附带堆栈，便于定位
                    if (function_exists('config') ? config('app_debug') : false) {
                        $errData['trace'] = $e->getTraceAsString();
                    }
                    $this->error($errMsg, null, $errData);
                } catch (\Throwable $e) {
                    if ($e instanceof \think\exception\HttpResponseException) {
                        throw $e;
                    }
                    Db::rollback();
                    $errMsg = '同步失败：' . ($e->getMessage() ?: get_class($e));
                    $errData = [
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                    ];
                    if (function_exists('config') ? config('app_debug') : false) {
                        $errData['trace'] = $e->getTraceAsString();
                    }
                    $this->error($errMsg, null, $errData);
                }
            } catch (\Exception $e) {
                if ($e instanceof \think\exception\HttpResponseException) {
                    throw $e;
                }
                $errMsg = '获取规范商品失败：' . ($e->getMessage() ?: get_class($e));
                $errData = [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ];
                if (function_exists('config') ? config('app_debug') : false) {
                    $errData['trace'] = $e->getTraceAsString();
                }
                $this->error($errMsg, null, $errData);
            } catch (\Throwable $e) {
                if ($e instanceof \think\exception\HttpResponseException) {
                    throw $e;
                }
                $errMsg = '获取规范商品失败：' . ($e->getMessage() ?: get_class($e));
                $errData = [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ];
                if (function_exists('config') ? config('app_debug') : false) {
                    $errData['trace'] = $e->getTraceAsString();
                }
                $this->error($errMsg, null, $errData);
            }
        }
        $this->error('非法请求');
    }
}
