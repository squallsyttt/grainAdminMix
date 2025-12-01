-- 商家后台核销记录 - 权限规则回滚
-- 创建日期: 2025-12-01
-- 说明: 删除新增的核销记录菜单及子权限

START TRANSACTION;

DELETE FROM `grain_auth_rule`
WHERE `name` IN (
  'index/wanlshop.voucher_verification/index',
  'index/wanlshop.voucher_verification/detail'
);

DELETE FROM `grain_auth_rule`
WHERE `name` = 'index/wanlshop.voucher_verification';

COMMIT;
