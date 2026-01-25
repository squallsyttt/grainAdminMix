-- 管理后台：新增“品牌类目/等级类目”菜单与权限
-- 创建日期: 2026-01-25
-- 说明:
-- - 尝试挂载到 wanlshop 下（若不存在则回退到根菜单）
-- - grain_auth_rule.name 通常存在唯一约束，因此“查看/新增/编辑/删除/multi/selectpage”等权限规则需保证只插入一次
-- - 本脚本设计为可重复执行（幂等）

SET @wanlshop_pid = (
  SELECT `id` FROM `grain_auth_rule`
  WHERE `name` IN ('wanlshop', 'wanlshop/index')
  ORDER BY `id` ASC
  LIMIT 1
);

SET @parent_pid = IFNULL(@wanlshop_pid, 0);

-- 1) 品牌类目菜单（仅插入一次）
INSERT INTO `grain_auth_rule`
(`type`, `pid`, `name`, `title`, `icon`, `condition`, `remark`, `ismenu`, `createtime`, `updatetime`, `weigh`, `status`)
SELECT 'file', @parent_pid, 'wanlshop/goods_meta_category/brand', '品牌类目', 'fa fa-tags', '', '商品品牌类目（多级）', 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 88, 'normal'
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM `grain_auth_rule` WHERE `name` = 'wanlshop/goods_meta_category/brand'
);

SET @brand_menu_id = (
  SELECT `id` FROM `grain_auth_rule`
  WHERE `name` = 'wanlshop/goods_meta_category/brand'
  ORDER BY `id` DESC
  LIMIT 1
);

-- 2) 等级类目菜单（仅插入一次）
INSERT INTO `grain_auth_rule`
(`type`, `pid`, `name`, `title`, `icon`, `condition`, `remark`, `ismenu`, `createtime`, `updatetime`, `weigh`, `status`)
SELECT 'file', @parent_pid, 'wanlshop/goods_meta_category/grade', '等级类目', 'fa fa-sitemap', '', '商品等级类目（多级）', 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 87, 'normal'
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM `grain_auth_rule` WHERE `name` = 'wanlshop/goods_meta_category/grade'
);

SET @grade_menu_id = (
  SELECT `id` FROM `grain_auth_rule`
  WHERE `name` = 'wanlshop/goods_meta_category/grade'
  ORDER BY `id` DESC
  LIMIT 1
);

-- 3) 公共权限规则（name 唯一，只插入一次；挂在 brand 菜单下，若不存在则挂在 grade 下）
SET @perm_pid = IFNULL(@brand_menu_id, @grade_menu_id);

INSERT INTO `grain_auth_rule`
(`type`, `pid`, `name`, `title`, `icon`, `condition`, `remark`, `ismenu`, `createtime`, `updatetime`, `weigh`, `status`)
SELECT 'file', @perm_pid, 'wanlshop/goods_meta_category/index', '查看', '', '', '', 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0, 'normal'
FROM DUAL
WHERE @perm_pid IS NOT NULL AND NOT EXISTS (
  SELECT 1 FROM `grain_auth_rule` WHERE `name` = 'wanlshop/goods_meta_category/index'
);

INSERT INTO `grain_auth_rule`
(`type`, `pid`, `name`, `title`, `icon`, `condition`, `remark`, `ismenu`, `createtime`, `updatetime`, `weigh`, `status`)
SELECT 'file', @perm_pid, 'wanlshop/goods_meta_category/add', '新增', '', '', '', 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0, 'normal'
FROM DUAL
WHERE @perm_pid IS NOT NULL AND NOT EXISTS (
  SELECT 1 FROM `grain_auth_rule` WHERE `name` = 'wanlshop/goods_meta_category/add'
);

INSERT INTO `grain_auth_rule`
(`type`, `pid`, `name`, `title`, `icon`, `condition`, `remark`, `ismenu`, `createtime`, `updatetime`, `weigh`, `status`)
SELECT 'file', @perm_pid, 'wanlshop/goods_meta_category/edit', '编辑', '', '', '', 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0, 'normal'
FROM DUAL
WHERE @perm_pid IS NOT NULL AND NOT EXISTS (
  SELECT 1 FROM `grain_auth_rule` WHERE `name` = 'wanlshop/goods_meta_category/edit'
);

INSERT INTO `grain_auth_rule`
(`type`, `pid`, `name`, `title`, `icon`, `condition`, `remark`, `ismenu`, `createtime`, `updatetime`, `weigh`, `status`)
SELECT 'file', @perm_pid, 'wanlshop/goods_meta_category/del', '删除', '', '', '', 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0, 'normal'
FROM DUAL
WHERE @perm_pid IS NOT NULL AND NOT EXISTS (
  SELECT 1 FROM `grain_auth_rule` WHERE `name` = 'wanlshop/goods_meta_category/del'
);

INSERT INTO `grain_auth_rule`
(`type`, `pid`, `name`, `title`, `icon`, `condition`, `remark`, `ismenu`, `createtime`, `updatetime`, `weigh`, `status`)
SELECT 'file', @perm_pid, 'wanlshop/goods_meta_category/multi', '批量更新', '', '', '', 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0, 'normal'
FROM DUAL
WHERE @perm_pid IS NOT NULL AND NOT EXISTS (
  SELECT 1 FROM `grain_auth_rule` WHERE `name` = 'wanlshop/goods_meta_category/multi'
);

INSERT INTO `grain_auth_rule`
(`type`, `pid`, `name`, `title`, `icon`, `condition`, `remark`, `ismenu`, `createtime`, `updatetime`, `weigh`, `status`)
SELECT 'file', @perm_pid, 'wanlshop/goods_meta_category/selectpage', 'Selectpage', '', '', '', 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0, 'normal'
FROM DUAL
WHERE @perm_pid IS NOT NULL AND NOT EXISTS (
  SELECT 1 FROM `grain_auth_rule` WHERE `name` = 'wanlshop/goods_meta_category/selectpage'
);
