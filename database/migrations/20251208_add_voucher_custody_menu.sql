-- 核销券代管理菜单权限迁移
-- 创建日期: 2025-12-08
-- 说明: 在后台菜单新增代管理审核入口

-- 代管理菜单权限（挂在 wanlshop/voucher 下）
INSERT INTO `grain_auth_rule` (`type`, `pid`, `name`, `title`, `icon`, `ismenu`, `weigh`, `status`)
SELECT 'menu', id, 'wanlshop/voucher/custody', '代管理审核', 'fa fa-hand-paper-o', 1, 0, 'normal'
FROM `grain_auth_rule` WHERE `name` = 'wanlshop/voucher';

-- 子操作权限
SET @custody_pid = LAST_INSERT_ID();
INSERT INTO `grain_auth_rule` (`type`, `pid`, `name`, `title`, `ismenu`, `weigh`, `status`) VALUES
('file', @custody_pid, 'wanlshop/voucher/custody/index', '查看', 0, 0, 'normal'),
('file', @custody_pid, 'wanlshop/voucher/custody/approve', '审核通过', 0, 0, 'normal'),
('file', @custody_pid, 'wanlshop/voucher/custody/reject', '审核拒绝', 0, 0, 'normal'),
('file', @custody_pid, 'wanlshop/voucher/custody/detail', '详情', 0, 0, 'normal');
