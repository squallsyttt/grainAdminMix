define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {

    var Controller = {
        pending: function () {
            // 初始化表格参数配置
            Table.api.init({
                extend: {
                    index_url: 'wanlshop/voucher/user_invite_rebate/pending',
                    grant_url: 'wanlshop/voucher/user_invite_rebate/grantRebate',
                    cancel_url: 'wanlshop/voucher/user_invite_rebate/cancel',
                    table: 'user_invite_pending',
                }
            });

            var table = $("#table");

            // 初始化表格
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'createtime',
                sortOrder: 'desc',
                columns: [
                    [
                        {checkbox: true},
                        {field: 'id', title: 'ID', sortable: true},
                        {field: 'inviter_name', title: '邀请人', operate: 'LIKE'},
                        {field: 'inviter_mobile', title: '邀请人手机', operate: 'LIKE'},
                        {field: 'inviter_level', title: '邀请人等级', formatter: function(value) {
                            var labels = ['<span class="label label-default">Lv0</span>',
                                         '<span class="label label-info">Lv1</span>',
                                         '<span class="label label-success">Lv2</span>'];
                            return labels[value] || labels[0];
                        }},
                        {field: 'invitee_name', title: '被邀请人', operate: 'LIKE'},
                        {field: 'invitee_mobile', title: '被邀请人手机', operate: 'LIKE'},
                        {field: 'voucher_no', title: '券号', operate: 'LIKE'},
                        {field: 'goods_title', title: '商品', operate: 'LIKE'},
                        {field: 'face_value', title: '券面值', operate: false, formatter: function(value) {
                            return '¥' + parseFloat(value).toFixed(2);
                        }},
                        {field: 'bonus_ratio', title: '返利比例', operate: false, formatter: function(value) {
                            return value + '%';
                        }},
                        {field: 'rebate_amount', title: '预计返利', operate: false, formatter: function(value) {
                            return '<span class="text-success">¥' + parseFloat(value).toFixed(2) + '</span>';
                        }},
                        {field: 'time_hint', title: '24h状态', operate: false, formatter: function(value, row) {
                            if (row.is_refunded) {
                                return '<span class="text-danger">' + value + '</span>';
                            } else if (row.is_24h_passed) {
                                return '<span class="text-success">' + value + '</span>';
                            } else {
                                return '<span class="text-warning">' + value + '</span>';
                            }
                        }},
                        {field: 'verify_time', title: '核销时间', operate: false, formatter: Table.api.formatter.datetime, sortable: true},
                        {field: 'operate', title: __('Operate'), table: table,
                            buttons: [
                                {
                                    name: 'grant',
                                    text: '发放',
                                    title: '发放返利',
                                    classname: 'btn btn-xs btn-success btn-grant btn-dialog',
                                    icon: 'fa fa-check',
                                    url: $.fn.bootstrapTable.defaults.extend.grant_url,
                                    visible: function(row) {
                                        return row.can_grant;
                                    }
                                },
                                {
                                    name: 'cancel',
                                    text: '取消',
                                    title: '取消',
                                    classname: 'btn btn-xs btn-danger btn-cancel btn-ajax',
                                    icon: 'fa fa-times',
                                    url: $.fn.bootstrapTable.defaults.extend.cancel_url,
                                    confirm: '确认取消该记录？',
                                    success: function(data, ret) {
                                        table.bootstrapTable('refresh');
                                    }
                                }
                            ],
                            events: Table.api.events.operate,
                            formatter: Table.api.formatter.operate
                        }
                    ]
                ]
            });

            // 为表格绑定事件
            Table.api.bindevent(table);
        },
        index: function () {
            // 已发放返利列表（打款管理）
            Table.api.init({
                extend: {
                    index_url: 'wanlshop/voucher/user_invite_rebate/index',
                    transfer_url: 'wanlshop/voucher/user_invite_rebate/transfer',
                    table: 'wanlshop_voucher_rebate',
                }
            });

            var table = $("#table");

            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'createtime',
                sortOrder: 'desc',
                columns: [
                    [
                        {checkbox: true},
                        {field: 'id', title: 'ID', sortable: true},
                        {field: 'user.nickname', title: '邀请人'},
                        {field: 'user.mobile', title: '邀请人手机'},
                        {field: 'invitee_nickname', title: '被邀请人'},
                        {field: 'voucher_no', title: '券号'},
                        {field: 'face_value', title: '券面值', formatter: function(value) {
                            return '¥' + parseFloat(value).toFixed(2);
                        }},
                        {field: 'rebate_amount', title: '返利金额', formatter: function(value) {
                            return '<span class="text-success">¥' + parseFloat(value).toFixed(2) + '</span>';
                        }},
                        {field: 'bonus_ratio', title: '返利比例', formatter: function(value) {
                            return value + '%';
                        }},
                        {field: 'payment_status', title: '打款状态', searchList: {'unpaid':'未打款','pending':'打款中','paid':'已打款','failed':'打款失败'}, formatter: function(value) {
                            var map = {
                                'unpaid': '<span class="label label-warning">未打款</span>',
                                'pending': '<span class="label label-info">打款中</span>',
                                'paid': '<span class="label label-success">已打款</span>',
                                'failed': '<span class="label label-danger">打款失败</span>'
                            };
                            return map[value] || value;
                        }},
                        {field: 'createtime', title: '创建时间', formatter: Table.api.formatter.datetime},
                        {field: 'operate', title: __('Operate'), table: table,
                            buttons: [
                                {
                                    name: 'transfer',
                                    text: '打款',
                                    title: '打款',
                                    classname: 'btn btn-xs btn-success btn-dialog',
                                    icon: 'fa fa-money',
                                    url: $.fn.bootstrapTable.defaults.extend.transfer_url,
                                    visible: function(row) {
                                        return row.can_transfer;
                                    }
                                }
                            ],
                            events: Table.api.events.operate,
                            formatter: Table.api.formatter.operate
                        }
                    ]
                ]
            });

            Table.api.bindevent(table);
        },
        grantrebate: function () {
            Controller.api.bindevent();
        },
        transfer: function () {
            Controller.api.bindevent();
        },
        api: {
            bindevent: function () {
                Form.api.bindevent($("form[role=form]"));
            }
        }
    };

    return Controller;
});
