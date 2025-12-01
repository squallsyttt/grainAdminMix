-- 商家后台核销记录 - 权限规则与菜单
-- 创建日期: 2025-12-01
-- 说明: 为商家后台新增核销记录菜单及子权限，优先挂载到 index/wanlshop 根节点

-- 1) 获取父级菜单ID（按优先级回退）
SET @parent_id = (
  SELECT id FROM `grain_auth_rule` WHERE `name` = 'index/wanlshop' LIMIT 1
);
SET @parent_id = COALESCE(
  @parent_id,
  (SELECT id FROM `grain_auth_rule` WHERE `name` = 'index/wanlshop.voucher' LIMIT 1),
  (SELECT id FROM `grain_auth_rule` WHERE `name` = 'wanlshop/voucher' LIMIT 1),
  (SELECT id FROM `grain_auth_rule` WHERE `name` = 'wanlshop' LIMIT 1)
);

-- 若仍未找到父级则作为顶级节点插入（@parent_id 会被置为 0）
SET @parent_id = IFNULL(@parent_id, 0);

-- 2) 清理同名规则，避免重复执行冲突
DELETE FROM `grain_auth_rule`
WHERE `name` IN (
  'index/wanlshop.voucher_verification/index',
  'index/wanlshop.voucher_verification/detail',
  'index/wanlshop.voucher_verification'
);

SET @now = UNIX_TIMESTAMP();

-- 3) 插入菜单节点：核销记录
INSERT INTO `grain_auth_rule`
(`type`, `pid`, `name`, `title`, `icon`, `url`, `condition`, `remark`, `ismenu`, `menutype`, `extend`, `py`, `pinyin`, `createtime`, `updatetime`, `weigh`, `status`)
VALUES
('file', @parent_id, 'index/wanlshop.voucher_verification', '核销记录', 'fa fa-check-square', '', '', '商家核销记录菜单', 1, NULL, '', '', '', @now, @now, 100, 'normal');

SET @voucher_verification_menu_id = LAST_INSERT_ID();

-- 4) 插入子权限：列表与详情
INSERT INTO `grain_auth_rule`
(`type`, `pid`, `name`, `title`, `icon`, `url`, `condition`, `remark`, `ismenu`, `menutype`, `extend`, `py`, `pinyin`, `createtime`, `updatetime`, `weigh`, `status`)
VALUES
('file', @voucher_verification_menu_id, 'index/wanlshop.voucher_verification/index', '查看列表', '', '', '', '', 0, NULL, '', '', '', @now, @now, 0, 'normal'),
('file', @voucher_verification_menu_id, 'index/wanlshop.voucher_verification/detail', '查看详情', '', '', '', '', 0, NULL, '', '', '', @now, @now, 0, 'normal');
