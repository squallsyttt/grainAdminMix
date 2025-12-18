define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {

    var Controller = {
        index: function () {
            // 初始化表格参数配置
            Table.api.init({
                extend: {
                    index_url: 'wanlshop/salesman/task/index' + location.search,
                    add_url: 'wanlshop/salesman/task/add',
                    edit_url: 'wanlshop/salesman/task/edit',
                    del_url: 'wanlshop/salesman/task/del',
                    multi_url: 'wanlshop/salesman/task/multi',
                    table: 'salesman_task',
                }
            });

            var table = $("#table");

            // 初始化表格
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'weigh',
                sortOrder: 'desc',
                columns: [
                    [
                        {checkbox: true},
                        {field: 'id', title: __('ID'), sortable: true},
                        {field: 'name', title: __('任务名称'), operate: 'LIKE'},
                        {field: 'type_text', title: __('任务类型'), searchList: {"user_verify": __('邀请用户核销'), "shop_verify": __('邀请商家核销'), "rebate_amount": __('累计返利金额')}},
                        {field: 'target_text', title: __('目标')},
                        {field: 'reward_amount', title: __('奖励金额'), formatter: function(value) {
                            return '¥' + value;
                        }},
                        {field: 'participant_count', title: __('参与人数')},
                        {field: 'completed_count', title: __('完成人数')},
                        {field: 'status_text', title: __('状态'), searchList: {"normal": __('启用'), "disabled": __('禁用')}, formatter: Table.api.formatter.status},
                        {field: 'weigh', title: __('排序'), sortable: true},
                        {field: 'createtime', title: __('创建时间'), operate:'RANGE', addclass:'datetimerange', autocomplete:false, formatter: Table.api.formatter.datetime},
                        {field: 'operate', title: __('操作'), table: table, events: Table.api.events.operate, formatter: Table.api.formatter.operate}
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
