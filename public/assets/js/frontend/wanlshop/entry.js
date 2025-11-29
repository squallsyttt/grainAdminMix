"use strict";
define(['jquery', 'bootstrap', 'upload', 'backend', 'form', 'vue', 'common/region-data'], function ($, undefined, Upload, Backend, Form, Vue, RegionDataLib) {

    var Controller = {
        index: function () {
            new Vue({
                el: '#app',
                data: function () {
                    return {
                        state: 1
                    };
                },
                mounted: function () {
                    this.state = Config.entry && Config.entry.state !== undefined ? Config.entry.state : 1;
                    console.log(this.state);
                },
                methods: {
                    getName: function (t) {
                        return ['姓名', '企业名称', '企业名称'][t];
                    },
                    getNumber: function (t) {
                        return ['身份证号码', '统一信用代码', '统一信用代码'][t];
                    },
                    getImage: function (t) {
                        return ['手持身份证', '营业执照', '营业执照'][t];
                    }
                }
            });

            Form.api.bindevent($('form[role=form]'), function (data, ret) {
                setTimeout(function () {
                    location.href = Fast.api.fixurl('wanlshop/entry/stepthree.html');
                }, 500);
            });
        },
        stepthree: function () {
            var RegionLib = arguments.length > 6 ? arguments[6] : RegionDataLib;
            var regionData = RegionLib && RegionLib.regionData ? RegionLib.regionData : [];
            var $ = require('jquery');

            // 配送城市选择器初始化
            var provinceSelect = $('#delivery-province');
            var citySelect = $('#delivery-city');
            var codeInput = $('#delivery_city_code');
            var nameInput = $('#delivery_city_name');

            // 填充省份
            regionData.forEach(function (province) {
                provinceSelect.append('<option value="' + province.id + '">' + province.name + '</option>');
            });

            // 省份变化时更新城市
            provinceSelect.on('change', function () {
                var provinceId = $(this).val();
                citySelect.html('<option value="">请选择城市</option>');
                codeInput.val('');
                nameInput.val('');

                if (provinceId) {
                    var province = regionData.find(function (p) {
                        return p.id === provinceId;
                    });
                    if (province && province.children) {
                        province.children.forEach(function (city) {
                            citySelect.append('<option value="' + city.id + '" data-name="' + city.name + '">' + city.name + '</option>');
                        });
                    }
                }
            });

            // 城市变化时更新隐藏字段
            citySelect.on('change', function () {
                var selectedOption = $(this).find('option:selected');
                codeInput.val(selectedOption.val());
                nameInput.val(selectedOption.data('name') || '');
            });

            // 回显已有数据
            var savedCode = codeInput.val();
            if (savedCode) {
                regionData.forEach(function (province) {
                    if (province.children) {
                        province.children.forEach(function (city) {
                            if (city.id === savedCode) {
                                provinceSelect.val(province.id).trigger('change');
                                setTimeout(function () {
                                    citySelect.val(savedCode);
                                }, 100);
                            }
                        });
                    }
                });
            }

            // 原有表单绑定逻辑
            Form.api.bindevent($('form[role=form]'), function (data, ret) {
                setTimeout(function () {
                    location.href = Fast.api.fixurl('wanlshop/entry/stepfour.html');
                }, 500);
            });
        },
        stepfour: function () {
        }
    };

    return Controller;
});
