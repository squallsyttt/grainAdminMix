define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {

    var custodyStateMap = {
        '0': {text: '未申请', className: 'label-default'},
        '1': {text: '申请中', className: 'label-warning'},
        '2': {text: '已通过', className: 'label-success'},
        '3': {text: '已拒绝', className: 'label-danger'}
    };

    var Formatter = {
        money: function (value) {
            if (value === null || value === undefined || value === '') {
                return '-';
            }
            var number = parseFloat(value);
            if (isNaN(number)) {
                return '-';
            }
            return '¥' + number.toFixed(2);
        },
        custodyState: function (value, row) {
            var current = custodyStateMap[value] || {text: '未知', className: 'label-default'};
            return '<span class="label ' + current.className + '">' + current.text + '</span>';
        }
    };

    var Controller = {
        index: function () {
            // 初始化表格参数配置
            Table.api.init({
                extend: {
                    index_url: 'wanlshop/voucher/custody/index' + location.search,
                    detail_url: 'wanlshop/voucher/custody/detail',
                    approve_url: 'wanlshop/voucher/custody/approve',
                    reject_url: 'wanlshop/voucher/custody/reject',
                    add_url: '',
                    edit_url: '',
                    del_url: '',
                    multi_url: '',
                    table: 'wanlshop_voucher',
                }
            });

            var table = $("#table");

            // 初始化表格
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'custody_apply_time',
                sortOrder: 'desc',
                search: false,
                commonSearch: true,
                searchFormVisible: true,
                columns: [
                    [
                        {checkbox: true},
                        {field: 'id', title: 'ID', sortable: true},
                        {field: 'voucher_no', title: '券号', operate: 'LIKE'},
                        {field: 'user.nickname', title: '用户昵称', operate: 'LIKE'},
                        {field: 'user.mobile', title: '用户手机', operate: 'LIKE'},
                        {field: 'goods_title', title: '商品名称', align: 'left', operate: 'LIKE'},
                        {field: 'sku_difference', title: '规格', operate: false},
                        {field: 'face_value', title: '券面值', operate: 'BETWEEN', formatter: Formatter.money},
                        {field: 'custody_platform_price', title: '平台基准价', operate: 'BETWEEN', formatter: Formatter.money},
                        {field: 'custody_estimated_rebate', title: '预估返利', operate: 'BETWEEN', formatter: Formatter.money},
                        {
                            field: 'custody_apply_time',
                            title: '申请时间',
                            operate: 'RANGE',
                            addclass: 'datetimerange',
                            sortable: true,
                            formatter: Table.api.formatter.datetime
                        },
                        {
                            field: 'custody_state',
                            title: '代管理状态',
                            searchList: {'1': '申请中', '2': '已通过', '3': '已拒绝'},
                            formatter: Formatter.custodyState
                        },
                        {
                            field: 'custody_refuse_reason',
                            title: '拒绝理由',
                            operate: false,
                            formatter: function(value) {
                                return value || '-';
                            }
                        },
                        {
                            field: 'operate',
                            title: '操作',
                            table: table,
                            events: Controller.api.events.operate,
                            formatter: Controller.api.formatter.operate
                        }
                    ]
                ]
            });

            // 为表格绑定事件
            Table.api.bindevent(table);

            // 批量审核通过
            $(document).on('click', '.btn-approve', function () {
                var selectedIds = Table.api.selectedids(table);
                if (selectedIds.length === 0) {
                    Toastr.warning('请选择要审核的记录');
                    return;
                }

                layer.confirm('确认批量审核通过 ' + selectedIds.length + ' 条记录？', {
                    title: '批量审核通过',
                    btn: ['确定', '取消']
                }, function (index) {
                    Fast.api.ajax({
                        url: 'wanlshop/voucher/custody/approve',
                        data: {ids: selectedIds.join(',')}
                    }, function () {
                        layer.close(index);
                        table.bootstrapTable('refresh');
                        return false;
                    }, function () {
                        layer.close(index);
                    });
                });
            });

            // 批量审核拒绝
            $(document).on('click', '.btn-reject', function () {
                var selectedIds = Table.api.selectedids(table);
                if (selectedIds.length === 0) {
                    Toastr.warning('请选择要拒绝的记录');
                    return;
                }

                layer.prompt({
                    formType: 2,
                    title: '请输入拒绝理由',
                    area: ['500px', '150px']
                }, function (reason, index) {
                    if (!reason || reason.trim() === '') {
                        Toastr.warning('请输入拒绝理由');
                        return;
                    }
                    Fast.api.ajax({
                        url: 'wanlshop/voucher/custody/reject',
                        data: {
                            ids: selectedIds.join(','),
                            refuse_reason: reason.trim()
                        }
                    }, function () {
                        layer.close(index);
                        table.bootstrapTable('refresh');
                        return false;
                    }, function () {
                        layer.close(index);
                    });
                });
            });

            // 监听选中项变化，控制批量按钮状态
            table.on('check.bs.table uncheck.bs.table check-all.bs.table uncheck-all.bs.table', function () {
                var selectedIds = Table.api.selectedids(table);
                if (selectedIds.length > 0) {
                    $('.btn-approve, .btn-reject').removeClass('btn-disabled disabled');
                } else {
                    $('.btn-approve, .btn-reject').addClass('btn-disabled disabled');
                }
            });
        },
        detail: function () {
        },
        api: {
            bindevent: function () {
                Form.api.bindevent($("form[role=form]"));
            },
            formatter: {
                // 操作栏按钮
                operate: function (value, row, index) {
                    var buttons = [];
                    var custodyState = row.custody_state;

                    // 详情按钮
                    buttons.push('<a href="javascript:;" class="btn btn-xs btn-info btn-dialog" data-url="wanlshop/voucher/custody/detail?ids=' + row.id + '" title="查看详情"><i class="fa fa-eye"></i></a>');

                    // 申请中状态：显示通过/拒绝按钮
                    if (custodyState === '1') {
                        buttons.push('<a href="javascript:;" class="btn btn-xs btn-success btn-single-approve" data-id="' + row.id + '" title="审核通过"><i class="fa fa-check"></i></a>');
                        buttons.push('<a href="javascript:;" class="btn btn-xs btn-danger btn-single-reject" data-id="' + row.id + '" title="审核拒绝"><i class="fa fa-times"></i></a>');
                    }

                    return buttons.join(' ');
                }
            },
            events: {
                operate: {
                    'click .btn-dialog': function (e, value, row, index) {
                        e.stopPropagation();
                        var url = $(this).data('url');
                        Fast.api.open(url, $(this).attr('title') || '详情');
                    },
                    'click .btn-single-approve': function (e, value, row, index) {
                        e.stopPropagation();
                        var id = $(this).data('id');
                        var table = $("#table");
                        layer.confirm('确认审核通过？', {
                            title: '审核通过',
                            btn: ['确定', '取消']
                        }, function (layerIndex) {
                            Fast.api.ajax({
                                url: 'wanlshop/voucher/custody/approve',
                                data: {ids: id}
                            }, function () {
                                layer.close(layerIndex);
                                table.bootstrapTable('refresh');
                                return false;
                            }, function () {
                                layer.close(layerIndex);
                            });
                        });
                    },
                    'click .btn-single-reject': function (e, value, row, index) {
                        e.stopPropagation();
                        var id = $(this).data('id');
                        var table = $("#table");
                        layer.prompt({
                            formType: 2,
                            title: '请输入拒绝理由',
                            area: ['500px', '150px']
                        }, function (reason, layerIndex) {
                            if (!reason || reason.trim() === '') {
                                Toastr.warning('请输入拒绝理由');
                                return;
                            }
                            Fast.api.ajax({
                                url: 'wanlshop/voucher/custody/reject',
                                data: {
                                    ids: id,
                                    refuse_reason: reason.trim()
                                }
                            }, function () {
                                layer.close(layerIndex);
                                table.bootstrapTable('refresh');
                                return false;
                            }, function () {
                                layer.close(layerIndex);
                            });
                        });
                    }
                }
            }
        }
    };
    return Controller;
});
