<?php
namespace app\api\model\wanlshop;

use think\Model;
use fast\Random;

class Shop extends Model
{

    // 表名
    protected $name = 'wanlshop_shop';
    
    // 自动写入时间戳字段
    protected $autoWriteTimestamp = 'int';

    // 定义时间戳字段名
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';
	
	// 追加属性
	protected $append = [
		'find_user',
		'category_tags'
	];

	// 获取店铺 发现号
	public function getFindUserAttr($value, $data)
	{
		$find = [];
		$findModel = new find\User;
		$row = $findModel
			->where(['user_id' => $data['user_id']])
			->find();
		if(!$row){
			$findModel->user_id = $data['user_id'];
			$findModel->user_no = Random::nozero(9);
			$findModel->save();
			$find = [
				'user_no' => $findModel->user_no,
				'fans' => 0
			];
		}else{
			$find = [
				'user_no' => $row->user_no,
				'fans' => $row->fans
			];
		}
		return $find;
	}
	
	public function getServiceIdsAttr($value, $data)
	{
	    $value = $value ? $value : (isset($data['service_ids']) ? $data['service_ids'] : '');
	    $valueArr = explode(',', $value);
		$service = [];
		foreach(ShopService::all($valueArr) as $vo){
		   $service[] =  [
			   'id' => $vo['id'],
			   'name' => $vo['name'],
			   'description' => $vo['description']
		   ];
		}
		return $service;
	}

	/**
	 * 获取店铺商品分类标签
	 * 检查店铺商品所属分类名称是否包含"惠选"或"精选"关键字
	 * @param mixed $value
	 * @param array $data
	 * @return array 返回标签数组 ['huixuan' => bool, 'jingxuan' => bool]
	 */
	public function getCategoryTagsAttr($value, $data)
	{
		$tags = [
			'huixuan' => false,
			'jingxuan' => false
		];

		if (!isset($data['id'])) {
			return $tags;
		}

		// 查询该店铺所有商品的分类ID
		$categoryIds = \think\Db::name('wanlshop_goods')
			->where('shop_id', $data['id'])
			->where('category_id', '>', 0)
			->column('category_id');

		if (empty($categoryIds)) {
			return $tags;
		}

		// 去重
		$categoryIds = array_unique($categoryIds);

		// 查询这些分类的名称
		$categoryNames = \think\Db::name('wanlshop_category')
			->whereIn('id', $categoryIds)
			->column('name');

		// 检查是否包含惠选或精选关键字
		foreach ($categoryNames as $name) {
			if (strpos($name, '惠选') !== false) {
				$tags['huixuan'] = true;
			}
			if (strpos($name, '精选') !== false) {
				$tags['jingxuan'] = true;
			}
			// 如果两个标签都已找到，提前退出
			if ($tags['huixuan'] && $tags['jingxuan']) {
				break;
			}
		}

		return $tags;
	}
}
