define(['jquery', 'bootstrap', 'backend', 'table', 'form', 'vue'], function ($, undefined, Backend, Table, Form, Vue) {
    var Controller = {
		bill: function () {
		    // 初始化表格参数配置
		    Table.api.init({
		        extend: {
		            index_url: 'wanlshop/finance/bill' + location.search,
		            add_url: '',
		            edit_url: '',
		            del_url: '',
		            multi_url: '',
		            table: 'wanlshop_page',
		        }
		    });
		    var table = $("#table");
		    // 初始化表格
		    table.bootstrapTable({
		        url: $.fn.bootstrapTable.defaults.extend.index_url,
		        pk: 'id',
		        sortName: 'id',
				fixedColumns: true,
                fixedRightNumber: 1,
		        columns: [
		            [
		                {checkbox: true},
		                {field: 'id', title: __('Id')},
		                {field: 'money', title: __('Money'), operate:'BETWEEN'},
                        {field: 'before', title: __('Before'), operate:'BETWEEN'},
                        {field: 'after', title: __('After'), operate:'BETWEEN'},
                        {field: 'type', title: __('Type'), searchList: {"pay":__('Type pay'),"refund":__('Type refund'),"sys":__('Type sys')}, formatter: Table.api.formatter.normal},
                        {field: 'service_ids', title: __('Service_ids'), operate: 'LIKE'},
                        {field: 'createtime', title: __('Createtime'), operate:'RANGE', addclass:'datetimerange', autocomplete:false, formatter: Table.api.formatter.datetime},
                        {field: 'operate', title: __('Operate'), table: table, events: Table.api.events.operate,buttons: [
							{name: 'detail',title: __('查看'),text: __('查看'),classname: 'btn btn-xs btn-info btn-dialog',
							icon: 'fa fa-eye',url: 'wanlshop/finance/billDetail'},
							{
								name: 'type',
								title: function (row) {
									return `${__('Type ' + row.type)}详情`;
								},
								text: function (row) {
									return `${__('Type ' + row.type)}详情`;
								},
								classname: 'btn btn-xs btn-danger btn-dialog',
								icon: 'fa fa-eye',
								extend: 'data-area=\'["980px", "650px"]\'',
								url: function (row) {
									var url = '链接异常';
									switch(row.type) {
									    case "pay": url = 'wanlshop/order/detail/order_no'; break; // 商品交易
										case "refund": url = 'wanlshop/refund/detail/order_no'; break; // 退款 1.1.3升级
									}
									return `${url}/${row.service_ids}`;
								},
								visible: function (row) {
								    return row.type && row.type !== 'sys';
								}
							},
						],formatter: Table.api.formatter.operate}
		            ]
		        ]
		    });
		    // 为表格绑定事件
		    Table.api.bindevent(table);
		},
		api: {
			formatter: {
				url: function (value, row, index) {
				    return '<a href="' + row.fullurl + '" target="_blank" class="label bg-green">' + value + '</a>';
				}
			},
		    bindevent: function () {
		        Form.api.bindevent($("form[role=form]"));
		    }
		}
	};
    return Controller;
});