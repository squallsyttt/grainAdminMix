define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {

    var Controller = {
        index: function () {
            // 初始化表格参数配置
            Table.api.init({
                extend: {
                    index_url: 'wanlshop/voucher.settlement/index' + location.search,
                    add_url: '',
                    edit_url: '',
                    del_url: '',
                    multi_url: '',
                    table: 'wanlshop_voucher_settlement',
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
                        {field: 'settlement_no', title: '结算单号'},
                        {field: 'voucher.voucher_no', title: '券号'},
                        {field: 'shop_name', title: '店铺', align: 'left'},
                        {field: 'user.username', title: '用户', align: 'left', formatter: Table.api.formatter.search},
                        {field: 'retail_price', title: '零售价', operate: 'BETWEEN'},
                        {field: 'supply_price', title: '供货价', operate: 'BETWEEN'},
                        {field: 'shop_amount', title: '商家结算金额', operate: 'BETWEEN'},
                        {field: 'platform_amount', title: '平台利润', operate: 'BETWEEN'},
                        {
                            field: 'state',
                            title: '结算状态',
                            searchList: {'1': '待结算', '2': '已结算'},
                            formatter: Table.api.formatter.normal
                        },
                        {
                            field: 'createtime',
                            title: '创建时间',
                            operate: 'RANGE',
                            addclass: 'datetimerange',
                            formatter: Table.api.formatter.datetime
                        },
                        {
                            field: 'settlement_time',
                            title: '结算时间',
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

