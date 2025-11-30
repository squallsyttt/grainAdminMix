define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {

    var Controller = {
        index: function () {
            // 初始化表格参数配置
            Table.api.init({
                extend: {
                    index_url: 'wanlshop/voucher.rule/index' + location.search,
                    add_url: 'wanlshop/voucher.rule/add',
                    edit_url: 'wanlshop/voucher.rule/edit',
                    del_url: 'wanlshop/voucher.rule/del',
                    multi_url: 'wanlshop/voucher.rule/multi',
                    table: 'wanlshop_voucher_rule',
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
                        {field: 'name', title: '规则名称', align: 'left'},
                        {field: 'expire_days', title: '过期天数'},
                        {field: 'free_days', title: '免费存放天数'},
                        {field: 'welfare_days', title: '福利消耗天数'},
                        {field: 'goods_days', title: '货物消耗天数'},
                        {field: 'priority', title: '优先级'},
                        {
                            field: 'state',
                            title: '状态',
                            visible: false,
                            searchList: {'1': '启用', '0': '禁用'},
                            formatter: Table.api.formatter.normal
                        },
                        {field: 'state_text', title: '状态'},
                        {field: 'voucher_count', title: '关联券数'},
                        {
                            field: 'createtime',
                            title: '创建时间',
                            operate: 'RANGE',
                            addclass: 'datetimerange',
                            formatter: Table.api.formatter.datetime
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
        api: {
            bindevent: function () {
                Form.api.bindevent($("form[role=form]"));
            }
        }
    };
    return Controller;
});
