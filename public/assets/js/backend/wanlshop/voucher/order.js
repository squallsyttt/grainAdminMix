define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {

    var Controller = {
        index: function () {
            // 初始化表格参数配置
            Table.api.init({
                extend: {
                    index_url: 'wanlshop/voucher.order/index' + location.search,
                    add_url: '',
                    edit_url: '',
                    del_url: 'wanlshop/voucher.order/del',
                    multi_url: 'wanlshop/voucher.order/multi',
                    table: 'wanlshop_voucher_order',
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
                        {field: 'user.username', title: '用户', align: 'left', formatter: Table.api.formatter.search},
                        {field: 'goods.title', title: '商品', align: 'left', formatter: Table.api.formatter.search},
                        {field: 'region_city_name', title: '发布城市', formatter: Table.api.formatter.search},
                        {field: 'category.name', title: '分类', align: 'left'},
                        {field: 'order_no', title: '订单号'},
                        {field: 'quantity', title: '数量'},
                        {field: 'retail_price', title: '零售价', operate: 'BETWEEN'},
                        {field: 'actual_payment', title: '实付金额', operate: 'BETWEEN'},
                        {
                            field: 'state',
                            title: '订单状态',
                            searchList: {'1': '待支付', '2': '已支付', '3': '已取消'},
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
                            field: 'paymenttime',
                            title: '付款时间',
                            operate: 'RANGE',
                            addclass: 'datetimerange',
                            formatter: Table.api.formatter.datetime
                        },
                        {
                            field: 'status',
                            title: '行状态',
                            searchList: {'normal': 'Normal', 'hidden': 'Hidden'},
                            formatter: Table.api.formatter.status
                        },
                        {field: 'operate', title: __('Operate'), table: table, events: Table.api.events.operate, formatter: Table.api.formatter.operate}
                    ]
                ]
            });

            // 为表格绑定事件
            Table.api.bindevent(table);
        },
        add: function () {
            Controller.api.bindevent();
        },
        edit: function () {
            Controller.api.bindevent();
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
