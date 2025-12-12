define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {

    var Controller = {
        pending: function () {
            // 初始化表格参数配置
            Table.api.init({
                extend: {
                    index_url: 'wanlshop/voucher/shop_invite_rebate/pending',
                    grant_url: 'wanlshop/voucher/shop_invite_rebate/grantRebate',
                    cancel_url: 'wanlshop/voucher/shop_invite_rebate/cancel',
                    table: 'shop_invite_pending',
                }
            });

            var table = $("#table");

            // 初始化表格
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'p.createtime',
                sortOrder: 'desc',
                columns: [
                    [
                        {checkbox: true},
                        {field: 'id', title: __('Id'), sortable: true},
                        {field: 'shopname', title: '店铺名称', operate: 'LIKE'},
                        {field: 'inviter_name', title: '邀请人', operate: 'LIKE'},
                        {field: 'inviter_mobile', title: '邀请人手机', operate: 'LIKE'},
                        {field: 'inviter_level', title: '当前等级', formatter: function(value) {
                            var labels = ['<span class="label label-default">Lv0</span>',
                                         '<span class="label label-info">Lv1</span>',
                                         '<span class="label label-success">Lv2</span>'];
                            return labels[value] || labels[0];
                        }},
                        {field: 'supply_price', title: '供货价', operate: false, formatter: function(value) {
                            return '¥' + parseFloat(value).toFixed(2);
                        }},
                        {field: 'estimated_amount', title: '预估返利', operate: false, formatter: function(value, row) {
                            return '<span class="text-success">¥' + parseFloat(value).toFixed(2) + '</span> (' + row.estimated_ratio + '%)';
                        }},
                        {field: 'hours_passed', title: '已过时间', operate: false, formatter: function(value, row) {
                            if (row.is_24h_passed) {
                                return '<span class="text-success">' + value + 'h ✓</span>';
                            } else {
                                var left = (24 - value).toFixed(1);
                                return '<span class="text-warning">' + value + 'h (还差' + left + 'h)</span>';
                            }
                        }},
                        {field: 'is_refunded', title: '退款状态', operate: false, formatter: function(value) {
                            return value ? '<span class="text-danger">已退款</span>' : '<span class="text-success">正常</span>';
                        }},
                        {field: 'verify_time', title: '核销时间', operate: false, formatter: Table.api.formatter.datetime},
                        {field: 'operate', title: __('Operate'), table: table,
                            buttons: [
                                {
                                    name: 'grant',
                                    text: '发放返利',
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
                                    classname: 'btn btn-xs btn-danger btn-ajax',
                                    icon: 'fa fa-times',
                                    url: $.fn.bootstrapTable.defaults.extend.cancel_url,
                                    confirm: '确认取消此记录？',
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
                    index_url: 'wanlshop/voucher/shop_invite_rebate/index',
                    transfer_url: 'wanlshop/voucher/shop_invite_rebate/transfer',
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
                        {field: 'id', title: __('Id'), sortable: true},
                        {field: 'user.nickname', title: '邀请人'},
                        {field: 'user.mobile', title: '邀请人手机'},
                        {field: 'invite_shop_name', title: '被邀请店铺'},
                        {field: 'rebate_amount', title: '返利金额', formatter: function(value) {
                            return '¥' + parseFloat(value).toFixed(2);
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
