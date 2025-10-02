define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {

    var Controller = {
        index: function () {
            // 初始化表格参数配置
            Table.api.init();
            
            //绑定事件
            $('a[data-toggle="tab"]').on('shown.bs.tab', function (e) {
                var panel = $($(this).attr("href"));
                if (panel.length > 0) {
                    Controller.table[panel.attr("id")].call(this);
                    $(this).on('click', function (e) {
                        $($(this).attr("href")).find(".btn-refresh").trigger("click");
                    });
                }
                //移除绑定的事件
                $(this).unbind('shown.bs.tab');
            });
            
            //必须默认触发shown.bs.tab事件
            $('ul.nav-tabs li.active a[data-toggle="tab"]').trigger("shown.bs.tab");
        },
        table: {
            first: function () {
                // 表格1
                var image = $("#image");
                image.bootstrapTable({
                    url: 'wanlshop/captcha/index',
                    extend: {
                        index_url: 'wanlshop/captcha/index',
                        add_url: 'wanlshop/captcha/add',
                        edit_url: 'wanlshop/captcha/edit',
                        del_url: 'wanlshop/captcha/del',
                        multi_url: 'wanlshop/captcha/multi'
                    },
                    toolbar: '#image_toolbar',
                    sortName: 'id',
                    search: false,
                    columns: [
                        [
                            {field: 'state', checkbox: true, },
                            {field: 'id', title: 'ID'},
                            {field: 'file', title: __('File'), events: Table.api.events.image, formatter: Table.api.formatter.image},
                            {field: 'times', title: __('Times')},
                            {field: 'num', title: __('Num')},
                            {field: 'md5', title: __('MD5')},
                            {field: 'operate', title: __('Operate'), table: image, events: Table.api.events.operate, formatter: Table.api.formatter.operate}
                        ]
                    ]
                });

                // 为表格1绑定事件
                Table.api.bindevent(image);
            },
            second: function () {
                // 表格2
                var log = $("#log");
                log.bootstrapTable({
                    url: 'wanlshop/captcha/log',
                    extend: {
                        index_url: '',
                        add_url: '',
                        edit_url: '',
                        del_url: '',
                        multi_url: '',
                        table: '',
                    },
                    toolbar: '#log_toolbar',
                    sortName: 'id',
                    search: false,
                    columns: [
                        [
                            {field: 'id', title: 'ID'},
                            {field: 'captcha_id', title: __('CaptchaId')},
                            {field: 'angle', title: __('Angle'),formatter: Controller.api.formatter.angle},
                            {field: 'ip', title: __('Ip'),formatter: Controller.api.formatter.ip},
                            {field: 'succeedtime', title: __('Succeedtime'),formatter: Controller.api.formatter.succeedtime},
                            {field: 'times', title: __('Errtimes')},
                            {field: 'updatetime', title: __('Updatetimes'), formatter: Table.api.formatter.datetime, operate: 'RANGE', addclass: 'datetimerange', sortable: true},
                            {field: 'createtime', title: __('Createtime'), formatter: Table.api.formatter.datetime, operate: 'RANGE', addclass: 'datetimerange', sortable: true}
                        ]
                    ]
                });
                
                // 启动和暂停按钮
                $(document).on("click", ".btn-clear", function () {
                    Fast.api.ajax({
                       url:'wanlshop/captcha/clear',
                    }, (data, ret) => {
                       log.bootstrapTable('refresh');
                       return false;
                    });
                });
                // 为表格2绑定事件
                Table.api.bindevent(log);
            }
        },
        add: function () {
            Controller.api.bindevent();
        },
        edit: function () {
            Controller.api.bindevent();
        },
        api: {
            formatter: {//渲染的方法
                ip: function (value, row, index) {
                    return '<a class="btn btn-xs btn-ip bg-success"><i class="fa fa-map-marker"></i> ' + value + '</a>';
                },
                angle: function (value, row, index) {
                    return value + '°';
                },
                succeedtime: function (value, row, index) {
                    return value + 's';
                }
            },
            bindevent: function () {
                Form.api.bindevent($("form[role=form]"));
            },
        }
    };
    return Controller;
});