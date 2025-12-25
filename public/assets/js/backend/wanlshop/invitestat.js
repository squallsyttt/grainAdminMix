define(['jquery', 'bootstrap', 'backend', 'vue'], function ($, undefined, Backend, Vue) {
    var Controller = {
        index: function () {
            new Vue({
                el: '#invitestat-app',
                data: {
                    // 搜索
                    keyword: '',
                    loading: false,

                    // 邀请人列表
                    inviters: [],
                    inviterPage: 1,
                    inviterLimit: 20,
                    inviterTotal: 0,
                    inviterPages: 0,

                    // 展开的行ID（手风琴）
                    expandedId: null,
                    detailTab: 'users',
                    detailLoading: false,

                    // 邀请用户列表
                    invitedUsers: [],
                    invitedUserPage: 1,
                    invitedUserLimit: 10,
                    invitedUserTotal: 0,
                    invitedUserPages: 0,

                    // 邀请店铺列表
                    invitedShops: [],
                    invitedShopPage: 1,
                    invitedShopLimit: 10,
                    invitedShopTotal: 0,
                    invitedShopPages: 0
                },
                mounted: function () {
                    this.loadInviters();
                },
                methods: {
                    // 加载邀请人列表
                    loadInviters: function () {
                        var self = this;
                        self.loading = true;

                        $.ajax({
                            url: 'wanlshop/invitestat/getInviters',
                            type: 'POST',
                            data: {
                                page: self.inviterPage,
                                limit: self.inviterLimit,
                                keyword: self.keyword
                            },
                            dataType: 'json',
                            success: function (res) {
                                self.loading = false;
                                if (res.code === 1) {
                                    self.inviters = res.data.list || [];
                                    self.inviterTotal = res.data.total || 0;
                                    self.inviterPages = res.data.pages || 0;
                                } else {
                                    Toastr.error(res.msg || '加载失败');
                                }
                            },
                            error: function () {
                                self.loading = false;
                                Toastr.error('网络错误');
                            }
                        });
                    },

                    // 搜索
                    searchInviters: function () {
                        this.inviterPage = 1;
                        this.expandedId = null;
                        this.loadInviters();
                    },

                    // 翻页
                    changeInviterPage: function (page) {
                        if (page < 1 || page > this.inviterPages) return;
                        this.inviterPage = page;
                        this.expandedId = null;
                        this.loadInviters();
                    },

                    // 切换展开/收起（手风琴）
                    toggleExpand: function (inviter) {
                        if (this.expandedId === inviter.id) {
                            // 收起
                            this.expandedId = null;
                        } else {
                            // 展开新的
                            this.expandedId = inviter.id;
                            this.detailTab = 'users';
                            this.invitedUserPage = 1;
                            this.invitedShopPage = 1;
                            this.loadInvitedUsers(inviter.id);
                        }
                    },

                    // 切换标签页
                    switchTab: function (tab) {
                        this.detailTab = tab;
                        if (tab === 'users') {
                            this.loadInvitedUsers(this.expandedId);
                        } else {
                            this.loadInvitedShops(this.expandedId);
                        }
                    },

                    // 加载邀请用户列表
                    loadInvitedUsers: function (inviterId) {
                        if (!inviterId) return;

                        var self = this;
                        self.detailLoading = true;

                        $.ajax({
                            url: 'wanlshop/invitestat/getInvitedUsers',
                            type: 'POST',
                            data: {
                                inviter_id: inviterId,
                                page: self.invitedUserPage,
                                limit: self.invitedUserLimit
                            },
                            dataType: 'json',
                            success: function (res) {
                                self.detailLoading = false;
                                if (res.code === 1) {
                                    self.invitedUsers = res.data.list || [];
                                    self.invitedUserTotal = res.data.total || 0;
                                    self.invitedUserPages = res.data.pages || 0;
                                } else {
                                    Toastr.error(res.msg || '加载失败');
                                }
                            },
                            error: function () {
                                self.detailLoading = false;
                                Toastr.error('网络错误');
                            }
                        });
                    },

                    // 邀请用户翻页
                    changeInvitedUserPage: function (page) {
                        if (page < 1 || page > this.invitedUserPages) return;
                        this.invitedUserPage = page;
                        this.loadInvitedUsers(this.expandedId);
                    },

                    // 加载邀请店铺列表
                    loadInvitedShops: function (inviterId) {
                        if (!inviterId) return;

                        var self = this;
                        self.detailLoading = true;

                        $.ajax({
                            url: 'wanlshop/invitestat/getInvitedShops',
                            type: 'POST',
                            data: {
                                inviter_id: inviterId,
                                page: self.invitedShopPage,
                                limit: self.invitedShopLimit
                            },
                            dataType: 'json',
                            success: function (res) {
                                self.detailLoading = false;
                                if (res.code === 1) {
                                    self.invitedShops = res.data.list || [];
                                    self.invitedShopTotal = res.data.total || 0;
                                    self.invitedShopPages = res.data.pages || 0;
                                } else {
                                    Toastr.error(res.msg || '加载失败');
                                }
                            },
                            error: function () {
                                self.detailLoading = false;
                                Toastr.error('网络错误');
                            }
                        });
                    },

                    // 邀请店铺翻页
                    changeInvitedShopPage: function (page) {
                        if (page < 1 || page > this.invitedShopPages) return;
                        this.invitedShopPage = page;
                        this.loadInvitedShops(this.expandedId);
                    }
                }
            });
        }
    };
    return Controller;
});
