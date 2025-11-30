<?php

namespace app\api\controller\wanlshop;

use app\common\controller\Api;
use fast\Tree;
/**
 * WanlShop店铺接口
 */
class Shop extends Api
{
    protected $noNeedLogin = ['getShopInfo', 'lists','searchCity'];
    protected $noNeedRight = ['*'];
	
	public function _initialize()
	{
	    parent::_initialize();
		$this->model = model('app\api\model\wanlshop\Shop');
	}
	
	/**
	 * 一次性获取店铺相关数据 1.0.8升级
	 *
	 * @ApiSummary  (WanlShop 一次性获取店铺相关数据)
	 * @ApiMethod   (GET)
	 *
	 * @param string $id 页面ID
	 */
	public function getShopInfo($id = null)
	{
		
		//设置过滤方法
		$this->request->filter(['strip_tags']);
		// 获取店铺信息
		$row = $this->model->get($id);

		if (!$row) {
		    $this->error(__('未找到此商家'));
		}

			$this->success('返回成功', $row);
		}

	/**
	 * 获取当前登录用户的店铺信息
	 *
	 * @ApiSummary  (WanlShop 获取当前登录用户的店铺信息)
	 * @ApiMethod   (GET)
	 */
	public function myshop()
	{
		//设置过滤方法
		$this->request->filter(['strip_tags']);
		// 根据当前登录用户查找店铺
		$row = $this->model->get(['user_id' => $this->auth->id]);

		if (!$row) {
			$this->error(__('未找到此商家'));
		}

		$this->success('返回成功', $row);
	}

		/**
		 * 获取店铺列表
		 *
		 * @ApiSummary  (WanlShop 获取店铺列表)
		 * @ApiMethod   (GET)
		 *
		 * 可选参数：
		 * - search: 关键字（匹配店铺名、城市）
		 * - filter/op: 过滤与操作（JSON，参考商品列表用法）
		 * - sort/order: 排序字段/方向
		 */
		public function lists()
		{
			//设置过滤方法
			$this->request->filter(['strip_tags']);
			// 生成查询条件（支持 shopname, city 模糊搜索）
			list($where, $sort, $order) = $this->buildparams('shopname,city', false);
			// 查询数据，仅返回正常状态店铺
			$list = $this->model
				->where($where)
				->where('status', 'normal')
				->order($sort, $order)
				->paginate();

			$this->success('返回成功', $list);
		}

	/**
	 * 根据城市搜索店铺
	 *
	 * @ApiSummary  (WanlShop 根据城市搜索店铺)
	 * @ApiMethod   (GET)
	 *
	 * 可选参数：
	 * - search: 城市关键词（仅匹配 city）
	 * - filter/op: 过滤与操作（JSON，参考商品列表用法）
	 * - sort/order: 排序字段/方向
	 */
	public function searchCity()
	{
		// 设置过滤方法
		$this->request->filter(['strip_tags']);
		// 仅针对 city 字段做模糊搜索（LIKE %keyword%）
		list($where, $sort, $order) = $this->buildparams('city', false);
		// 查询数据，仅返回正常状态店铺
		$list = $this->model
			->where($where)
			->where('status', 'normal')
			->order($sort, $order)
			->paginate();

		$this->success('返回成功', $list);
	}

		/**
		 * 复制自产品控制器的参数构建器，适配API查询
		 * 仅返回 where/sort/order，便于列表分页
		 */
		protected function buildparams($searchfields = null, $relationSearch = null)
		{
			$search = $this->request->get("search", '');
			$filter = $this->request->get("filter", '');
			$op = $this->request->get("op", '', 'trim');
			$sort = $this->request->get("sort", !empty($this->model) && $this->model->getPk() ? $this->model->getPk() : 'id');
			$order = $this->request->get("order", "DESC");
			$filter = (array)json_decode($filter, true);
			$op = (array)json_decode($op, true);
			$filter = $filter ? $filter : [];
			$where = [];
			$tableName = '';
			if ($relationSearch) {
				if (!empty($this->model)) {
					$name = \think\Loader::parseName(basename(str_replace('\\\\', '/', get_class($this->model))));
					$name = $this->model->getTable();
					$tableName = $name . '.';
				}
				$sortArr = explode(',', $sort);
				foreach ($sortArr as $index => & $item) {
					$item = stripos($item, ".") === false ? $tableName . trim($item) : $item;
				}
				unset($item);
				$sort = implode(',', $sortArr);
			}

			if ($search) {
				$searcharr = is_array($searchfields) ? $searchfields : explode(',', $searchfields);
				foreach ($searcharr as $k => &$v) {
					$v = stripos($v, ".") === false ? $tableName . $v : $v;
				}
				unset($v);
				$arrSearch = [];
				foreach (explode(" ", $search) as $ko) {
					$arrSearch[] = '%'.$ko.'%';
				}
				$where[] = [implode("|", $searcharr), "LIKE", $arrSearch];
			}
			foreach ($filter as $k => $v) {
				$sym = isset($op[$k]) ? $op[$k] : '=';
				if (stripos($k, ".") === false) {
					$k = $tableName . $k;
				}
				$v = !is_array($v) ? trim($v) : $v;
				$sym = strtoupper(isset($op[$k]) ? $op[$k] : $sym);
				switch ($sym) {
					case '=':
					case '<>':
						$where[] = [$k, $sym, (string)$v];
						break;
					case 'LIKE':
					case 'NOT LIKE':
					case 'LIKE %...%':
					case 'NOT LIKE %...%':
						$where[] = [$k, trim(str_replace('%...%', '', $sym)), "%{$v}%"];
						break;
					case '>':
					case '>=':
					case '<':
					case '<=':
						$where[] = [$k, $sym, intval($v)];
						break;
					case 'FINDIN':
					case 'FINDINSET':
					case 'FIND_IN_SET':
						$where[] = "FIND_IN_SET('{$v}', " . ($relationSearch ? $k : '`' . str_replace('.', '`.`', $k) . '`') . ")";
						break;
					case 'IN':

					case 'IN(...)':
					case 'NOT IN':
					case 'NOT IN(...)':
						$where[] = [$k, str_replace('(...)', '', $sym), is_array($v) ? $v : explode(',', $v)];
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
						$where[] = [$k, $sym, $arr];
						break;
					case 'RANGE':
					case 'NOT RANGE':
						$v = str_replace(' - ', ',', $v);
						$arr = array_slice(explode(',', $v), 0, 2);
						if (stripos($v, ',') === false || !array_filter($arr)) {
							continue 2;
						}
						if ($arr[0] === '') {
							$sym = $sym == 'RANGE' ? '<=' : '>';
							$arr = $arr[1];
						} elseif ($arr[1] === '') {
							$sym = $sym == 'RANGE' ? '>=' : '<';
							$arr = $arr[0];
						}
						$where[] = [$k, str_replace('RANGE', 'BETWEEN', $sym) . ' time', $arr];
						break;
					case 'LIKE':
					case 'LIKE %...%':
						$where[] = [$k, 'LIKE', "%{$v}%"];
						break;
					case 'NULL':
					case 'IS NULL':
					case 'NOT NULL':
					case 'IS NOT NULL':
						$where[] = [$k, strtolower(str_replace('IS ', '', $sym))];
						break;
					default:
						break;
				}
			}
			$where = function ($query) use ($where) {
				foreach ($where as $k => $v) {
					if (is_array($v)) {
						call_user_func_array([$query, 'where'], $v);
					} else {
						$query->where($v);
					}
				}
			};
			return [$where, $sort, $order];
		}

	}
