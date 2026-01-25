define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {
  var Controller = {
    brand: function () {
      Controller.api.initTable({
        index_url: 'wanlshop/goods_meta_category/brand' + location.search,
        add_url: 'wanlshop/goods_meta_category/add?type=brand',
        edit_url: 'wanlshop/goods_meta_category/edit?type=brand',
        del_url: 'wanlshop/goods_meta_category/del',
        multi_url: 'wanlshop/goods_meta_category/multi',
        table: 'wanlshop_goods_meta_category'
      })
    },
    grade: function () {
      Controller.api.initTable({
        index_url: 'wanlshop/goods_meta_category/grade' + location.search,
        add_url: 'wanlshop/goods_meta_category/add?type=grade',
        edit_url: 'wanlshop/goods_meta_category/edit?type=grade',
        del_url: 'wanlshop/goods_meta_category/del',
        multi_url: 'wanlshop/goods_meta_category/multi',
        table: 'wanlshop_goods_meta_category'
      })
    },
    add: function () {
      Controller.api.bindevent()
    },
    edit: function () {
      Controller.api.bindevent()
    },
    api: {
      initTable: function (extend) {
        Table.api.init({ extend: extend })
        var table = $('#table')

        table.bootstrapTable({
          url: $.fn.bootstrapTable.defaults.extend.index_url,
          pk: 'id',
          sortName: 'weigh',
          pagination: false,
          fixedColumns: true,
          fixedRightNumber: 1,
          columns: [
            [
              { checkbox: true },
              { field: 'id', title: __('Id') },
              { field: 'name', title: __('Name'), align: 'left', formatter: Controller.api.formatter.escape2Html },
              { field: 'weigh', title: __('Weigh'), operate: false },
              { field: 'status', title: __('Status'), searchList: { 'normal': __('Normal'), 'hidden': __('Hidden') }, formatter: Table.api.formatter.status },
              { field: 'id', title: __('展开'), operate: false, formatter: Controller.api.formatter.subnode },
              { field: 'operate', title: __('Operate'), table: table, events: Table.api.events.operate, formatter: Table.api.formatter.operate }
            ]
          ],
          search: false,
          commonSearch: false
        })

        table.on('post-body.bs.table', function () {
          // 默认折叠：隐藏所有非根节点
          $('.btn-node-sub[data-pid!=0]').closest('tr').hide()

          $('.btn-node-sub').off('click').on('click', function () {
            var status = $(this).data('shown') || $("a.btn[data-pid='" + $(this).data('id') + "']:visible").size() > 0 ? true : false
            $("a.btn[data-pid='" + $(this).data('id') + "']").each(function () {
              $(this).closest('tr').toggle(!status)
              if (!$(this).hasClass('disabled')) {
                $(this).trigger('click')
              }
            })
            $(this).data('shown', !status)
            return false
          })
        })

        $(document.body).on('click', '.btn-toggle-all', function () {
          var that = this
          var show = $('i', that).hasClass('fa-plus')
          $('i', that).toggleClass('fa-plus', !show)
          $('i', that).toggleClass('fa-minus', show)
          $('.btn-node-sub[data-pid!=0]').closest('tr').toggle(show)
          $('.btn-node-sub[data-pid!=0]').data('shown', show)
        })

        Table.api.bindevent(table)
      },
      bindevent: function () {
        Form.api.bindevent($('form[role=form]'))
      },
      formatter: {
        escape2Html: function (value) {
          if (value === null || value === undefined) return ''
          return $('<div />').text(value).html()
        },
        subnode: function (value, row) {
          var cids = row.channel || []
          if (cids.length > 0) {
            return '<a href="javascript:;" data-toggle="tooltip" title="' + __('Toggle sub menu') + '" data-id="' + row.id + '" data-pid="' + row.pid + '" class="btn btn-xs btn-success btn-node-sub"><i class="fa fa-sitemap"></i></a>'
          }
          return '<a href="javascript:;" data-toggle="tooltip" title="' + __('Toggle sub menu') + '" data-id="' + row.id + '" data-pid="' + row.pid + '" class="btn btn-xs btn-default disabled btn-node-sub"><i class="fa fa-sitemap"></i></a>'
        }
      }
    }
  }

  return Controller
})
