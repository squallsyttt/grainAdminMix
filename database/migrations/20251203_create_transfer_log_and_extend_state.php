<?php

use think\migration\Migrator;

class CreateTransferLogAndExtendState extends Migrator
{
    public function up()
    {
        // 先扩展结算表状态枚举
        $this->execute(
            "ALTER TABLE `grain_wanlshop_voucher_settlement`
MODIFY COLUMN `state` enum('1','2','3','4') COLLATE utf8mb4_general_ci NOT NULL DEFAULT '1'
COMMENT '状态：1=待结算,2=已结算,3=打款中,4=打款失败';"
        );

        $table = $this->table('wanlshop_transfer_log', [
            'id' => 'id',
            'signed' => false,
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_general_ci',
            'comment' => '结算打款日志',
        ]);

        $table->addColumn('settlement_id', 'integer', ['limit' => 10, 'signed' => false, 'comment' => '结算记录ID'])
            ->addColumn('out_batch_no', 'string', ['limit' => 64, 'comment' => '商户批次单号'])
            ->addColumn('out_detail_no', 'string', ['limit' => 64, 'comment' => '商户明细单号'])
            ->addColumn('transfer_amount', 'integer', ['limit' => 11, 'comment' => '转账金额（分）'])
            ->addColumn('receiver_openid', 'string', ['limit' => 64, 'comment' => '收款人openid'])
            ->addColumn('receiver_user_id', 'integer', ['limit' => 10, 'signed' => false, 'comment' => '收款人用户ID'])
            ->addColumn('status', 'integer', ['limit' => 1, 'default' => 1, 'signed' => false, 'comment' => '状态：1=处理中,2=成功,3=失败'])
            ->addColumn('wechat_batch_id', 'string', ['limit' => 64, 'null' => true, 'comment' => '微信批次单号'])
            ->addColumn('wechat_detail_id', 'string', ['limit' => 64, 'null' => true, 'comment' => '微信明细单号'])
            ->addColumn('fail_reason', 'string', ['limit' => 255, 'null' => true, 'comment' => '失败原因'])
            ->addColumn('request_data', 'text', ['null' => true, 'comment' => '请求数据'])
            ->addColumn('response_data', 'text', ['null' => true, 'comment' => '响应数据'])
            ->addColumn('createtime', 'integer', ['limit' => 10, 'signed' => false, 'null' => true])
            ->addColumn('updatetime', 'integer', ['limit' => 10, 'signed' => false, 'null' => true])
            ->addIndex('settlement_id')
            ->addIndex('status')
            ->addIndex('out_batch_no', ['unique' => true])
            ->addIndex('out_detail_no', ['unique' => true])
            ->create();
    }

    public function down()
    {
        $this->table('wanlshop_transfer_log')->drop()->save();

        $this->execute("UPDATE `grain_wanlshop_voucher_settlement` SET `state` = '1' WHERE `state` IN ('3','4');");
        $this->execute(
            "ALTER TABLE `grain_wanlshop_voucher_settlement`
MODIFY COLUMN `state` enum('1','2') COLLATE utf8mb4_general_ci NOT NULL DEFAULT '1'
COMMENT '状态:1=待结算,2=已结算';"
        );
    }
}
