<?php

namespace app\api\model\wanlshop;

use think\Model;

/**
 * 商品类目模型 - API专用
 */
class Category extends Model
{
    // 表名(完整表名: grain_wanlshop_category)
    protected $name = 'wanlshop_category';

    // 自动写入时间戳字段
    protected $autoWriteTimestamp = 'int';

    // 定义时间戳字段名
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';

    // 追加属性(用于API输出)
    protected $append = [
        'type_text',
        'flag_text'
    ];

    /**
     * 获取类型列表
     * @return array
     */
    public function getTypeList()
    {
        return [
            'article' => '文章',
            'goods' => '商品'
        ];
    }

    /**
     * 获取标签列表
     * @return array
     */
    public function getFlagList()
    {
        return [
            'hot' => '热门',
            'new' => '新品',
            'recommend' => '推荐'
        ];
    }

    /**
     * 类型中文显示
     * @param mixed $value
     * @param array $data
     * @return string
     */
    public function getTypeTextAttr($value, $data)
    {
        $value = $value ? $value : (isset($data['type']) ? $data['type'] : '');
        $list = $this->getTypeList();
        return isset($list[$value]) ? $list[$value] : '';
    }

    /**
     * 标签中文显示
     * @param mixed $value
     * @param array $data
     * @return string
     */
    public function getFlagTextAttr($value, $data)
    {
        $value = $value ? $value : (isset($data['flag']) ? $data['flag'] : '');
        $valueArr = explode(',', $value);
        $list = $this->getFlagList();
        return implode(',', array_intersect_key($list, array_flip($valueArr)));
    }
}
