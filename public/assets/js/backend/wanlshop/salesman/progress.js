define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {

    var Controller = {
        index: function () {
            // 初始化表格参数配置
            Table.api.init({
                extend: {
                    index_url: 'wanlshop/salesman/progress/index' + location.search,
                    audit_url: 'wanlshop/salesman/progress/audit',
                    grant_url: 'wanlshop/salesman/progress/grant',
                    cancel_url: 'wanlshop/salesman/progress/cancel',
                    batch_refresh_url: 'wanlshop/salesman/progress/batchRefresh',
                    table: 'salesman_task_progress',
                }
            });

            var table = $("#table");

            // 初始化表格
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'id',
                sortOrder: 'desc',
                columns: [
                    [
                        {checkbox: true},
                        {field: 'id', title: __('ID'), sortable: true},
                        {field: 'salesman.user.nickname', title: __('业务员'), operate: false},
                        {field: 'salesman.user.mobile', title: __('手机号'), operate: false},
                        {field: 'task.name', title: __('任务名称'), operate: false},
                        {field: 'task.type_text', title: __('任务类型'), operate: false},
                        {field: 'task.target_text', title: __('目标'), operate: false},
                        {field: 'progress', title: __('当前进度'), operate: false, formatter: function(value, row) {
                            if (row.task && row.task.type === 'rebate_amount') {
                                return '¥' + row.current_amount + ' (' + row.progress_percent + '%)';
                            }
                            return row.current_count + '个 (' + row.progress_percent + '%)';
                        }},
                        {field: 'reward_amount', title: __('奖励金额'), formatter: function(value) {
                            return '¥' + value;
                        }},
                        {field: 'state', title: __('状态'), searchList: {"0": __('进行中'), "1": __('待审核'), "2": __('待发放'), "3": __('已发放'), "4": __('已取消')}, formatter: function(value, row) {
                            var classMap = {
                                0: 'info',
                                1: 'warning',
                                2: 'primary',
                                3: 'success',
                                4: 'default'
                            };
                            var textMap = {
                                0: '进行中',
                                1: '待审核',
                                2: '待发放',
                                3: '已发放',
                                4: '已取消'
                            };
                            return '<span class="label label-' + (classMap[value] || 'default') + '">' + (textMap[value] || value) + '</span>';
                        }},
                        {field: 'createtime', title: __('创建时间'), operate:'RANGE', addclass:'datetimerange', autocomplete:false, formatter: Table.api.formatter.datetime},
                        {field: 'operate', title: __('操作'), table: table, events: Table.api.events.operate, formatter: function(value, row, index) {
                            var that = $.extend({}, this);
                            var table = $(that.table).clone(true);
                            that.table = table;
                            return Table.api.formatter.operate.call(that, value, row, index);
                        }, buttons: [
                            {
                                name: 'audit',
                                text: __('审核'),
                                title: __('审核'),
                                classname: 'btn btn-xs btn-warning btn-dialog',
                                icon: 'fa fa-check',
                                url: $.fn.bootstrapTable.defaults.extend.audit_url,
                                visible: function(row) {
                                    return row.state == 1;
                                }
                            },
                            {
                                name: 'grant',
                                text: __('发放'),
                                title: __('发放奖励'),
                                classname: 'btn btn-xs btn-success btn-dialog',
                                icon: 'fa fa-gift',
                                url: $.fn.bootstrapTable.defaults.extend.grant_url,
                                visible: function(row) {
                                    return row.state == 2;
                                }
                            },
                            {
                                name: 'cancel',
                                text: __('取消'),
                                classname: 'btn btn-xs btn-danger btn-ajax',
                                icon: 'fa fa-times',
                                url: $.fn.bootstrapTable.defaults.extend.cancel_url,
                                confirm: '确定要取消此任务吗？',
                                visible: function(row) {
                                    return row.state <= 2;
                                },
                                success: function(data) {
                                    table.bootstrapTable('refresh');
                                }
                            }
                        ]}
                    ]
                ]
            });

            // 批量刷新进度
            $(document).on('click', '.btn-batch-refresh', function() {
                var ids = Table.api.selectedids(table);
                if (ids.length === 0) {
                    Toastr.warning('请先选择要刷新的记录');
                    return;
                }
                Backend.api.ajax({
                    url: $.fn.bootstrapTable.defaults.extend.batch_refresh_url,
                    data: {ids: ids.join(',')}
                }, function() {
                    table.bootstrapTable('refresh');
                });
            });

            // 为表格绑定事件
            Table.api.bindevent(table);
        },
        pending: function () {
            // 初始化表格参数配置
            Table.api.init({
                extend: {
                    index_url: 'wanlshop/salesman/progress/pending' + location.search,
                    audit_url: 'wanlshop/salesman/progress/audit',
                    table: 'salesman_task_progress',
                }
            });

            var table = $("#table");

            // 初始化表格
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'complete_time',
                sortOrder: 'asc',
                columns: [
                    [
                        {checkbox: true},
                        {field: 'id', title: __('ID'), sortable: true},
                        {field: 'salesman.user.nickname', title: __('业务员'), operate: false},
                        {field: 'salesman.user.mobile', title: __('手机号'), operate: false},
                        {field: 'task.name', title: __('任务名称'), operate: false},
                        {field: 'task.type_text', title: __('任务类型'), operate: false},
                        {field: 'task.target_text', title: __('目标'), operate: false},
                        {field: 'progress', title: __('完成进度'), operate: false, formatter: function(value, row) {
                            if (row.task && row.task.type === 'rebate_amount') {
                                return '¥' + row.current_amount;
                            }
                            return row.current_count + '个';
                        }},
                        {field: 'reward_amount', title: __('奖励金额'), formatter: function(value) {
                            return '<span class="text-danger">¥' + value + '</span>';
                        }},
                        {field: 'complete_time', title: __('完成时间'), sortable: true, formatter: Table.api.formatter.datetime},
                        {field: 'operate', title: __('操作'), table: table, events: Table.api.events.operate, formatter: function(value, row, index) {
                            var that = $.extend({}, this);
                            var table = $(that.table).clone(true);
                            that.table = table;
                            return Table.api.formatter.operate.call(that, value, row, index);
                        }, buttons: [
                            {
                                name: 'audit',
                                text: __('审核'),
                                title: __('审核任务'),
                                classname: 'btn btn-xs btn-warning btn-dialog',
                                icon: 'fa fa-check',
                                url: $.fn.bootstrapTable.defaults.extend.audit_url
                            }
                        ]}
                    ]
                ]
            });

            // 为表格绑定事件
            Table.api.bindevent(table);
        },
        audit: function () {
            Controller.api.bindevent();
        },
        grant: function () {
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
