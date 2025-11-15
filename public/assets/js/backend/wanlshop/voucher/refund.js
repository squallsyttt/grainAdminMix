define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {

    var Controller = {
        index: function () {
            // 初始化表格参数配置
            Table.api.init({
                extend: {
                    index_url: 'wanlshop/voucher.refund/index' + location.search,
                    add_url: '',
                    edit_url: '',
                    del_url: '',
                    multi_url: '',
                    table: 'wanlshop_voucher_refund',
                }
            });

            var table = $("#table");

            // 初始化表格
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'id',
                columns: [
                    [
                        {checkbox: true},
                        {field: 'id', title: 'ID'},
                        {field: 'refund_no', title: '退款单号'},
                        {field: 'voucher.voucher_no', title: '券号'},
                        {field: 'voucher.goods_title', title: '商品'},
                        {field: 'user.username', title: '用户', align: 'left', formatter: Table.api.formatter.search},
                        {field: 'refund_amount', title: '退款金额', operate: 'BETWEEN'},
                        {
                            field: 'state',
                            title: '退款状态',
                            searchList: {'0': '申请中', '1': '同意退款', '2': '拒绝退款', '3': '退款成功'},
                            formatter: Table.api.formatter.normal
                        },
                        {
                            field: 'createtime',
                            title: '申请时间',
                            operate: 'RANGE',
                            addclass: 'datetimerange',
                            formatter: Table.api.formatter.datetime
                        },
                        {
                            field: 'updatetime',
                            title: '更新时间',
                            operate: 'RANGE',
                            addclass: 'datetimerange',
                            formatter: Table.api.formatter.datetime
                        },
                        {
                            field: 'status',
                            title: '行状态',
                            searchList: {'normal': 'Normal', 'hidden': 'Hidden'},
                            formatter: Table.api.formatter.status
                        }
                    ]
                ]
            });

            // 为表格绑定事件
            Table.api.bindevent(table);
        },
        detail: function () {
        },
        api: {
            bindevent: function () {
                Form.api.bindevent($("form[role=form]"));
            }
        }
    };
    return Controller;
});

