"use strict";
(self["webpackChunk"] = self["webpackChunk"] || []).push([["clients"],{

/***/ "./node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/src/views/menu/clients/list-clients.vue?vue&type=script&setup=true&lang=js":
/*!*****************************************************************************************************************************************************************************************************************************!*\
  !*** ./node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/src/views/menu/clients/list-clients.vue?vue&type=script&setup=true&lang=js ***!
  \*****************************************************************************************************************************************************************************************************************************/
/***/ ((__unused_webpack_module, __webpack_exports__, __webpack_require__) => {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (__WEBPACK_DEFAULT_EXPORT__)
/* harmony export */ });
/* harmony import */ var vue__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! vue */ "./node_modules/vue/dist/vue.esm-bundler.js");
/* harmony import */ var vue_router__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! vue-router */ "./node_modules/vue-router/dist/vue-router.esm-bundler.js");
/* harmony import */ var _composables_use_meta__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! @/composables/use-meta */ "./resources/js/src/composables/use-meta.js");



/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = ({
  __name: 'list-clients',
  setup: function setup(__props, _ref) {
    var expose = _ref.expose;
    expose();
    (0,_composables_use_meta__WEBPACK_IMPORTED_MODULE_1__.useMeta)({
      title: 'Clients'
    });
    var columns = (0,vue__WEBPACK_IMPORTED_MODULE_0__.ref)(['name', 'phone_number', 'extra_phone_number', 'address', 'advocate', 'actions']);
    var items = (0,vue__WEBPACK_IMPORTED_MODULE_0__.ref)([]);
    var table_option = (0,vue__WEBPACK_IMPORTED_MODULE_0__.ref)({
      perPage: 10,
      perPageValues: [5, 10, 20, 50],
      skin: 'table table-hover',
      columnsClasses: {
        actions: 'actions text-center'
      },
      pagination: {
        nav: 'scroll',
        chunk: 5
      },
      texts: {
        count: 'Showing {from} to {to} of {count}',
        filter: '',
        filterPlaceholder: 'Search...',
        limit: 'Results:'
      },
      sortable: ['name', 'phone_number', 'extra_phone_number', 'address', 'advocate'],
      sortIcon: {
        base: 'sort-icon-none',
        up: 'sort-icon-asc',
        down: 'sort-icon-desc'
      },
      resizableColumns: false
    });
    (0,vue__WEBPACK_IMPORTED_MODULE_0__.onMounted)(function () {
      bind_data();
    });
    var router = (0,vue_router__WEBPACK_IMPORTED_MODULE_2__.useRouter)();

    var bind_data = function bind_data() {
      items.value = [{
        id: 1,
        name: 'Tiger Nixon',
        phone_number: '0777262222',
        extra_phone_number: '07826827722',
        address: 'Penyslvenia',
        advocate: 'Advoccate 1'
      }];
    };

    var view_row = function view_row(item) {
      // alert('ID: ' + item.id + ', Name: ' + item.name);
      // navigate to given route
      // $router.push({ name: 'view-client', params: { id: item.id } });
      router.push({
        name: 'client-details',
        params: {
          id: item.id
        }
      });
    };

    var __returned__ = {
      columns: columns,
      items: items,
      table_option: table_option,
      router: router,
      bind_data: bind_data,
      view_row: view_row,
      onMounted: vue__WEBPACK_IMPORTED_MODULE_0__.onMounted,
      ref: vue__WEBPACK_IMPORTED_MODULE_0__.ref,
      useRouter: vue_router__WEBPACK_IMPORTED_MODULE_2__.useRouter,
      useMeta: _composables_use_meta__WEBPACK_IMPORTED_MODULE_1__.useMeta
    };
    Object.defineProperty(__returned__, '__isScriptSetup', {
      enumerable: false,
      value: true
    });
    return __returned__;
  }
});

/***/ }),

/***/ "./node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/templateLoader.js??ruleSet[1].rules[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/src/views/menu/clients/list-clients.vue?vue&type=template&id=256fa396":
/*!**********************************************************************************************************************************************************************************************************************************************************************************************!*\
  !*** ./node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/templateLoader.js??ruleSet[1].rules[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/src/views/menu/clients/list-clients.vue?vue&type=template&id=256fa396 ***!
  \**********************************************************************************************************************************************************************************************************************************************************************************************/
/***/ ((__unused_webpack_module, __webpack_exports__, __webpack_require__) => {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "render": () => (/* binding */ render)
/* harmony export */ });
/* harmony import */ var vue__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! vue */ "./node_modules/vue/dist/vue.esm-bundler.js");

var _hoisted_1 = {
  "class": "container layout-px-spacing"
};

var _hoisted_2 = /*#__PURE__*/(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("ul", {
  "class": "navbar-nav flex-row"
}, [/*#__PURE__*/(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("li", null, [/*#__PURE__*/(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", {
  "class": "page-header"
}, [/*#__PURE__*/(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("nav", {
  "class": "breadcrumb-one",
  "aria-label": "breadcrumb"
}, [/*#__PURE__*/(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("ol", {
  "class": "breadcrumb"
}, [/*#__PURE__*/(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("li", {
  "class": "breadcrumb-item"
}, [/*#__PURE__*/(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("a", {
  href: "javascript:;"
}, "Clients")]), /*#__PURE__*/(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("li", {
  "class": "breadcrumb-item active",
  "aria-current": "page"
}, [/*#__PURE__*/(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", null, "List")])])])])])], -1
/* HOISTED */
);

var _hoisted_3 = /*#__PURE__*/(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", {
  "class": "row foat-right"
}, [/*#__PURE__*/(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("button", {
  type: "button",
  "class": "btn btn-secondary mb-2 me-2",
  "data-bs-toggle": "modal",
  "data-bs-target": "#registerModal"
}, "Add Client")], -1
/* HOISTED */
);

var _hoisted_4 = {
  "class": "row layout-top-spacing"
};
var _hoisted_5 = {
  "class": "col-xl-12 col-lg-12 col-sm-12 layout-spacing"
};
var _hoisted_6 = {
  "class": "panel br-6 p-0"
};
var _hoisted_7 = {
  "class": "custom-table"
};
var _hoisted_8 = {
  "class": "actions text-center"
};
var _hoisted_9 = ["onClick"];

var _hoisted_10 = /*#__PURE__*/(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("button", {
  type: "button",
  "class": "btn btn-primary btn-sm"
}, "View", -1
/* HOISTED */
);

var _hoisted_11 = [_hoisted_10];
var _hoisted_12 = ["onClick"];

var _hoisted_13 = /*#__PURE__*/(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("button", {
  type: "button",
  "class": "btn btn-success btn-sm"
}, "Update", -1
/* HOISTED */
);

var _hoisted_14 = [_hoisted_13];
var _hoisted_15 = {
  "class": "modal fade register-modal",
  id: "registerModal",
  tabindex: "-1",
  role: "dialog",
  "aria-labelledby": "registerModalLabel",
  "aria-hidden": "true"
};
var _hoisted_16 = {
  "class": "modal-dialog modal-sm",
  role: "document"
};
var _hoisted_17 = {
  "class": "modal-content"
};

var _hoisted_18 = /*#__PURE__*/(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", {
  "class": "modal-header",
  id: "registerModalLabel"
}, [/*#__PURE__*/(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("h4", {
  "class": "modal-title"
}, "Add Client"), /*#__PURE__*/(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("button", {
  type: "button",
  "data-dismiss": "modal",
  "data-bs-dismiss": "modal",
  "aria-label": "Close",
  "class": "btn-close"
})], -1
/* HOISTED */
);

var _hoisted_19 = {
  "class": "modal-body"
};
var _hoisted_20 = {
  id: "custom_styles",
  "class": "col-lg-12 layout-spacing col-md-12"
};
var _hoisted_21 = {
  "class": "statbox panel box box-shadow"
};

var _hoisted_22 = /*#__PURE__*/(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", {
  "class": "panel-heading"
}, [/*#__PURE__*/(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", {
  "class": "row"
}, [/*#__PURE__*/(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", {
  "class": "col-xl-12 col-md-12 col-sm-12 col-12"
}, [/*#__PURE__*/(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("h4", null, "Custom styles")])])], -1
/* HOISTED */
);

var _hoisted_23 = {
  "class": "panel-body"
};
var _hoisted_24 = {
  "class": "row"
};
var _hoisted_25 = {
  "class": "col-md-4 form-group"
};

var _hoisted_26 = /*#__PURE__*/(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("label", {
  "for": "validationCustom01"
}, "First name", -1
/* HOISTED */
);

var _hoisted_27 = /*#__PURE__*/(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", {
  "class": "valid-feedback"
}, "Looks good!", -1
/* HOISTED */
);

var _hoisted_28 = /*#__PURE__*/(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", {
  "class": "invalid-feedback"
}, "Please fill the first name", -1
/* HOISTED */
);

var _hoisted_29 = {
  "class": "col-md-4 form-group"
};

var _hoisted_30 = /*#__PURE__*/(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("label", {
  "for": "validationCustom02"
}, "Last name", -1
/* HOISTED */
);

var _hoisted_31 = /*#__PURE__*/(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", {
  "class": "valid-feedback"
}, "Looks good!", -1
/* HOISTED */
);

var _hoisted_32 = /*#__PURE__*/(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", {
  "class": "invalid-feedback"
}, "Please fill the last name", -1
/* HOISTED */
);

var _hoisted_33 = {
  "class": "col-md-4 form-group"
};

var _hoisted_34 = /*#__PURE__*/(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("label", {
  "for": "validationCustomUsername"
}, "Username", -1
/* HOISTED */
);

var _hoisted_35 = {
  "class": "input-group"
};

var _hoisted_36 = /*#__PURE__*/(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", {
  "class": "input-group-text",
  id: "inputGroupPrepend"
}, "@", -1
/* HOISTED */
);

var _hoisted_37 = /*#__PURE__*/(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", {
  "class": "valid-feedback"
}, "Looks good!", -1
/* HOISTED */
);

var _hoisted_38 = /*#__PURE__*/(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", {
  "class": "invalid-feedback"
}, "Please choose a username.", -1
/* HOISTED */
);

var _hoisted_39 = {
  "class": "row"
};
var _hoisted_40 = {
  "class": "col-md-6 form-group"
};

var _hoisted_41 = /*#__PURE__*/(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("label", {
  "for": "validationCustom03"
}, "City", -1
/* HOISTED */
);

var _hoisted_42 = /*#__PURE__*/(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", {
  "class": "valid-feedback"
}, "Looks good!", -1
/* HOISTED */
);

var _hoisted_43 = /*#__PURE__*/(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", {
  "class": "invalid-feedback"
}, "Please provide a valid city.", -1
/* HOISTED */
);

var _hoisted_44 = {
  "class": "col-md-3 form-group"
};

var _hoisted_45 = /*#__PURE__*/(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("label", {
  "for": "validationCustom04"
}, "State", -1
/* HOISTED */
);

var _hoisted_46 = /*#__PURE__*/(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", {
  "class": "valid-feedback"
}, "Looks good!", -1
/* HOISTED */
);

var _hoisted_47 = /*#__PURE__*/(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", {
  "class": "invalid-feedback"
}, "Please provide a valid state.", -1
/* HOISTED */
);

var _hoisted_48 = {
  "class": "col-md-3 form-group"
};

var _hoisted_49 = /*#__PURE__*/(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("label", {
  "for": "validationCustom05"
}, "Zip", -1
/* HOISTED */
);

var _hoisted_50 = /*#__PURE__*/(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", {
  "class": "valid-feedback"
}, "Looks good!", -1
/* HOISTED */
);

var _hoisted_51 = /*#__PURE__*/(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", {
  "class": "invalid-feedback"
}, "Please provide a valid zip.", -1
/* HOISTED */
);

var _hoisted_52 = {
  "class": "form-group form-group"
};
var _hoisted_53 = {
  "class": "form-check ps-0"
};
var _hoisted_54 = {
  "class": "custom-control custom-checkbox checkbox-success"
};

var _hoisted_55 = /*#__PURE__*/(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("label", {
  "class": "custom-control-label",
  "for": "invalidCheck"
}, "Agree to terms and conditions", -1
/* HOISTED */
);

var _hoisted_56 = /*#__PURE__*/(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", {
  "class": "invalid-feedback"
}, "You must agree before submitting.", -1
/* HOISTED */
);

var _hoisted_57 = /*#__PURE__*/(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("button", {
  "class": "btn btn-primary mt-3",
  type: "submit"
}, "Submit form", -1
/* HOISTED */
);

var _hoisted_58 = {
  "class": "code-section-container show-code"
};

var _hoisted_59 = /*#__PURE__*/(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", null, "Code", -1
/* HOISTED */
);

var _hoisted_60 = [_hoisted_59];
var _hoisted_61 = {
  key: 0,
  "class": "code-section text-start"
};

var _hoisted_62 = /*#__PURE__*/(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("pre", null, "<!-- Custom -->\n<form class=\"needs-validation\" novalidate @submit.stop.prevent=\"submit_form4\">\n    <div class=\"row\">\n        <div class=\"col-md-4 form-group\">\n            <label for=\"validationCustom01\">First name</label>\n            <input\n                type=\"text\"\n                v-model=\"form4.first_name\"\n                class=\"form-control\"\n                :class=\"[is_submit_form4 ? (form4.first_name ? 'is-valid' : 'is-invalid') : '']\"\n                id=\"validationCustom01\"\n                placeholder=\"First name\"\n            />\n            <div class=\"valid-feedback\">Looks good!</div>\n            <div class=\"invalid-feedback\">Please fill the first name</div>\n        </div>\n        <div class=\"col-md-4 form-group\">\n            <label for=\"validationCustom02\">Last name</label>\n            <input\n                type=\"text\"\n                v-model=\"form4.last_name\"\n                class=\"form-control\"\n                :class=\"[is_submit_form4 ? (form4.last_name ? 'is-valid' : 'is-invalid') : '']\"\n                id=\"validationCustom02\"\n                placeholder=\"Last name\"\n            />\n            <div class=\"valid-feedback\">Looks good!</div>\n            <div class=\"invalid-feedback\">Please fill the last name</div>\n        </div>\n        <div class=\"col-md-4 form-group\">\n            <label for=\"validationCustomUsername\">Username</label>\n            <div class=\"input-group\">\n                <span class=\"input-group-text\" id=\"inputGroupPrepend\">@</span>\n                <input\n                    type=\"text\"\n                    v-model=\"form4.username\"\n                    class=\"form-control\"\n                    :class=\"[is_submit_form4 ? (form4.username ? 'is-valid' : 'is-invalid') : '']\"\n                    id=\"validationCustomUsername\"\n                    placeholder=\"Username\"\n                    aria-describedby=\"inputGroupPrepend\"\n                />\n                <div class=\"valid-feedback\">Looks good!</div>\n                <div class=\"invalid-feedback\">Please choose a username.</div>\n            </div>\n        </div>\n    </div>\n    <div class=\"row\">\n        <div class=\"col-md-6 form-group\">\n            <label for=\"validationCustom03\">City</label>\n            <input\n                type=\"text\"\n                v-model=\"form4.city\"\n                class=\"form-control\"\n                :class=\"[is_submit_form4 ? (form4.city ? 'is-valid' : 'is-invalid') : '']\"\n                id=\"validationCustom03\"\n                placeholder=\"City\"\n            />\n            <div class=\"valid-feedback\">Looks good!</div>\n            <div class=\"invalid-feedback\">Please provide a valid city.</div>\n        </div>\n        <div class=\"col-md-3 form-group\">\n            <label for=\"validationCustom04\">State</label>\n            <input\n                type=\"text\"\n                v-model=\"form4.state\"\n                class=\"form-control\"\n                :class=\"[is_submit_form4 ? (form4.state ? 'is-valid' : 'is-invalid') : '']\"\n                id=\"validationCustom04\"\n                placeholder=\"State\"\n            />\n            <div class=\"valid-feedback\">Looks good!</div>\n            <div class=\"invalid-feedback\">Please provide a valid state.</div>\n        </div>\n        <div class=\"col-md-3 form-group\">\n            <label for=\"validationCustom05\">Zip</label>\n            <input\n                type=\"text\"\n                v-model=\"form4.zip\"\n                class=\"form-control\"\n                :class=\"[is_submit_form4 ? (form4.zip ? 'is-valid' : 'is-invalid') : '']\"\n                id=\"validationCustom05\"\n                placeholder=\"Zip\"\n            />\n            <div class=\"valid-feedback\">Looks good!</div>\n            <div class=\"invalid-feedback\">Please provide a valid zip.</div>\n        </div>\n    </div>\n    <div class=\"form-group form-group\">\n        <div class=\"form-check ps-0\">\n            <div class=\"custom-control custom-checkbox checkbox-success\">\n                <input\n                    type=\"checkbox\"\n                    v-model=\"form4.is_terms\"\n                    class=\"custom-control-input\"\n                    :class=\"[is_submit_form4 ? (form4.is_terms ? 'is-valid' : 'is-invalid') : '']\"\n                    id=\"invalidCheck\"\n                />\n                <label class=\"custom-control-label\" for=\"invalidCheck\">Agree to terms and conditions</label>\n                <div class=\"invalid-feedback\">You must agree before submitting.</div>\n            </div>\n        </div>\n    </div>\n    <button class=\"btn btn-primary mt-3\" type=\"submit\">Submit form</button>\n</form>\n", -1
/* HOISTED */
);

function render(_ctx, _cache, $props, $setup, $data, $options) {
  var _component_v_client_table = (0,vue__WEBPACK_IMPORTED_MODULE_0__.resolveComponent)("v-client-table");

  var _component_highlight = (0,vue__WEBPACK_IMPORTED_MODULE_0__.resolveComponent)("highlight");

  return (0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("div", _hoisted_1, [((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createBlock)(vue__WEBPACK_IMPORTED_MODULE_0__.Teleport, {
    to: "#breadcrumb"
  }, [_hoisted_2])), _hoisted_3, (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_4, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_5, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_6, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_7, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createVNode)(_component_v_client_table, {
    data: $setup.items,
    columns: $setup.columns,
    options: $setup.table_option
  }, {
    actions: (0,vue__WEBPACK_IMPORTED_MODULE_0__.withCtx)(function (props) {
      return [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_8, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("a", {
        href: "javascript:;",
        "class": "cancel",
        onClick: function onClick($event) {
          return $setup.view_row(props.row);
        }
      }, _hoisted_11, 8
      /* PROPS */
      , _hoisted_9), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("a", {
        href: "javascript:;",
        "class": "cancel",
        onClick: function onClick($event) {
          return _ctx.update_row(props.row);
        }
      }, _hoisted_14, 8
      /* PROPS */
      , _hoisted_12), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("                                    <router-link :to='view-client'>"), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("                                        <button type=\"button\" class=\"btn btn-primary btn-sm\">View</button>"), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("                                    </router-link>")])];
    }),
    salary: (0,vue__WEBPACK_IMPORTED_MODULE_0__.withCtx)(function (props) {
      return [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createTextVNode)(" $" + (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(props.row.salary), 1
      /* TEXT */
      )];
    }),
    _: 1
    /* STABLE */

  }, 8
  /* PROPS */
  , ["data", "columns", "options"])])])])]), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" Add Modal "), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_15, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_16, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_17, [_hoisted_18, (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_19, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_20, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_21, [_hoisted_22, (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_23, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("form", {
    "class": "needs-validation",
    novalidate: "",
    onSubmit: _cache[7] || (_cache[7] = (0,vue__WEBPACK_IMPORTED_MODULE_0__.withModifiers)(function () {
      return _ctx.submit_form4 && _ctx.submit_form4.apply(_ctx, arguments);
    }, ["stop", "prevent"]))
  }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_24, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_25, [_hoisted_26, (0,vue__WEBPACK_IMPORTED_MODULE_0__.withDirectives)((0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("input", {
    type: "text",
    "onUpdate:modelValue": _cache[0] || (_cache[0] = function ($event) {
      return _ctx.form4.first_name = $event;
    }),
    "class": (0,vue__WEBPACK_IMPORTED_MODULE_0__.normalizeClass)(["form-control", [_ctx.is_submit_form4 ? _ctx.form4.first_name ? 'is-valid' : 'is-invalid' : '']]),
    id: "validationCustom01",
    placeholder: "First name"
  }, null, 2
  /* CLASS */
  ), [[vue__WEBPACK_IMPORTED_MODULE_0__.vModelText, _ctx.form4.first_name]]), _hoisted_27, _hoisted_28]), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_29, [_hoisted_30, (0,vue__WEBPACK_IMPORTED_MODULE_0__.withDirectives)((0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("input", {
    type: "text",
    "onUpdate:modelValue": _cache[1] || (_cache[1] = function ($event) {
      return _ctx.form4.last_name = $event;
    }),
    "class": (0,vue__WEBPACK_IMPORTED_MODULE_0__.normalizeClass)(["form-control", [_ctx.is_submit_form4 ? _ctx.form4.last_name ? 'is-valid' : 'is-invalid' : '']]),
    id: "validationCustom02",
    placeholder: "Last name"
  }, null, 2
  /* CLASS */
  ), [[vue__WEBPACK_IMPORTED_MODULE_0__.vModelText, _ctx.form4.last_name]]), _hoisted_31, _hoisted_32]), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_33, [_hoisted_34, (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_35, [_hoisted_36, (0,vue__WEBPACK_IMPORTED_MODULE_0__.withDirectives)((0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("input", {
    type: "text",
    "onUpdate:modelValue": _cache[2] || (_cache[2] = function ($event) {
      return _ctx.form4.username = $event;
    }),
    "class": (0,vue__WEBPACK_IMPORTED_MODULE_0__.normalizeClass)(["form-control", [_ctx.is_submit_form4 ? _ctx.form4.username ? 'is-valid' : 'is-invalid' : '']]),
    id: "validationCustomUsername",
    placeholder: "Username",
    "aria-describedby": "inputGroupPrepend"
  }, null, 2
  /* CLASS */
  ), [[vue__WEBPACK_IMPORTED_MODULE_0__.vModelText, _ctx.form4.username]]), _hoisted_37, _hoisted_38])])]), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_39, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_40, [_hoisted_41, (0,vue__WEBPACK_IMPORTED_MODULE_0__.withDirectives)((0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("input", {
    type: "text",
    "onUpdate:modelValue": _cache[3] || (_cache[3] = function ($event) {
      return _ctx.form4.city = $event;
    }),
    "class": (0,vue__WEBPACK_IMPORTED_MODULE_0__.normalizeClass)(["form-control", [_ctx.is_submit_form4 ? _ctx.form4.city ? 'is-valid' : 'is-invalid' : '']]),
    id: "validationCustom03",
    placeholder: "City"
  }, null, 2
  /* CLASS */
  ), [[vue__WEBPACK_IMPORTED_MODULE_0__.vModelText, _ctx.form4.city]]), _hoisted_42, _hoisted_43]), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_44, [_hoisted_45, (0,vue__WEBPACK_IMPORTED_MODULE_0__.withDirectives)((0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("input", {
    type: "text",
    "onUpdate:modelValue": _cache[4] || (_cache[4] = function ($event) {
      return _ctx.form4.state = $event;
    }),
    "class": (0,vue__WEBPACK_IMPORTED_MODULE_0__.normalizeClass)(["form-control", [_ctx.is_submit_form4 ? _ctx.form4.state ? 'is-valid' : 'is-invalid' : '']]),
    id: "validationCustom04",
    placeholder: "State"
  }, null, 2
  /* CLASS */
  ), [[vue__WEBPACK_IMPORTED_MODULE_0__.vModelText, _ctx.form4.state]]), _hoisted_46, _hoisted_47]), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_48, [_hoisted_49, (0,vue__WEBPACK_IMPORTED_MODULE_0__.withDirectives)((0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("input", {
    type: "text",
    "onUpdate:modelValue": _cache[5] || (_cache[5] = function ($event) {
      return _ctx.form4.zip = $event;
    }),
    "class": (0,vue__WEBPACK_IMPORTED_MODULE_0__.normalizeClass)(["form-control", [_ctx.is_submit_form4 ? _ctx.form4.zip ? 'is-valid' : 'is-invalid' : '']]),
    id: "validationCustom05",
    placeholder: "Zip"
  }, null, 2
  /* CLASS */
  ), [[vue__WEBPACK_IMPORTED_MODULE_0__.vModelText, _ctx.form4.zip]]), _hoisted_50, _hoisted_51])]), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_52, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_53, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_54, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.withDirectives)((0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("input", {
    type: "checkbox",
    "onUpdate:modelValue": _cache[6] || (_cache[6] = function ($event) {
      return _ctx.form4.is_terms = $event;
    }),
    "class": (0,vue__WEBPACK_IMPORTED_MODULE_0__.normalizeClass)(["custom-control-input", [_ctx.is_submit_form4 ? _ctx.form4.is_terms ? 'is-valid' : 'is-invalid' : '']]),
    id: "invalidCheck"
  }, null, 2
  /* CLASS */
  ), [[vue__WEBPACK_IMPORTED_MODULE_0__.vModelCheckbox, _ctx.form4.is_terms]]), _hoisted_55, _hoisted_56])])]), _hoisted_57], 32
  /* HYDRATE_EVENTS */
  ), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_58, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("button", {
    type: "button",
    "class": "btn btn-default toggle-code-snippet",
    onClick: _cache[8] || (_cache[8] = function ($event) {
      return _ctx.toggleCode('code4');
    })
  }, _hoisted_60), _ctx.code_arr.includes('code4') ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("div", _hoisted_61, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createVNode)(_component_highlight, null, {
    "default": (0,vue__WEBPACK_IMPORTED_MODULE_0__.withCtx)(function () {
      return [_hoisted_62];
    }),
    _: 1
    /* STABLE */

  })])) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true)])])])])])])])])]);
}

/***/ }),

/***/ "./resources/js/src/views/menu/clients/list-clients.vue":
/*!**************************************************************!*\
  !*** ./resources/js/src/views/menu/clients/list-clients.vue ***!
  \**************************************************************/
/***/ ((__unused_webpack_module, __webpack_exports__, __webpack_require__) => {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (__WEBPACK_DEFAULT_EXPORT__)
/* harmony export */ });
/* harmony import */ var _list_clients_vue_vue_type_template_id_256fa396__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! ./list-clients.vue?vue&type=template&id=256fa396 */ "./resources/js/src/views/menu/clients/list-clients.vue?vue&type=template&id=256fa396");
/* harmony import */ var _list_clients_vue_vue_type_script_setup_true_lang_js__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! ./list-clients.vue?vue&type=script&setup=true&lang=js */ "./resources/js/src/views/menu/clients/list-clients.vue?vue&type=script&setup=true&lang=js");
/* harmony import */ var _home_lawrence_Documents_law_firm_new_ui_node_modules_vue_loader_dist_exportHelper_js__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! ./node_modules/vue-loader/dist/exportHelper.js */ "./node_modules/vue-loader/dist/exportHelper.js");




;
const __exports__ = /*#__PURE__*/(0,_home_lawrence_Documents_law_firm_new_ui_node_modules_vue_loader_dist_exportHelper_js__WEBPACK_IMPORTED_MODULE_2__["default"])(_list_clients_vue_vue_type_script_setup_true_lang_js__WEBPACK_IMPORTED_MODULE_1__["default"], [['render',_list_clients_vue_vue_type_template_id_256fa396__WEBPACK_IMPORTED_MODULE_0__.render],['__file',"resources/js/src/views/menu/clients/list-clients.vue"]])
/* hot reload */
if (false) {}


/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = (__exports__);

/***/ }),

/***/ "./resources/js/src/views/menu/clients/list-clients.vue?vue&type=script&setup=true&lang=js":
/*!*************************************************************************************************!*\
  !*** ./resources/js/src/views/menu/clients/list-clients.vue?vue&type=script&setup=true&lang=js ***!
  \*************************************************************************************************/
/***/ ((__unused_webpack_module, __webpack_exports__, __webpack_require__) => {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (/* reexport safe */ _node_modules_babel_loader_lib_index_js_clonedRuleSet_5_use_0_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_list_clients_vue_vue_type_script_setup_true_lang_js__WEBPACK_IMPORTED_MODULE_0__["default"])
/* harmony export */ });
/* harmony import */ var _node_modules_babel_loader_lib_index_js_clonedRuleSet_5_use_0_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_list_clients_vue_vue_type_script_setup_true_lang_js__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! -!../../../../../../node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!../../../../../../node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./list-clients.vue?vue&type=script&setup=true&lang=js */ "./node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/src/views/menu/clients/list-clients.vue?vue&type=script&setup=true&lang=js");
 

/***/ }),

/***/ "./resources/js/src/views/menu/clients/list-clients.vue?vue&type=template&id=256fa396":
/*!********************************************************************************************!*\
  !*** ./resources/js/src/views/menu/clients/list-clients.vue?vue&type=template&id=256fa396 ***!
  \********************************************************************************************/
/***/ ((__unused_webpack_module, __webpack_exports__, __webpack_require__) => {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "render": () => (/* reexport safe */ _node_modules_babel_loader_lib_index_js_clonedRuleSet_5_use_0_node_modules_vue_loader_dist_templateLoader_js_ruleSet_1_rules_2_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_list_clients_vue_vue_type_template_id_256fa396__WEBPACK_IMPORTED_MODULE_0__.render)
/* harmony export */ });
/* harmony import */ var _node_modules_babel_loader_lib_index_js_clonedRuleSet_5_use_0_node_modules_vue_loader_dist_templateLoader_js_ruleSet_1_rules_2_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_list_clients_vue_vue_type_template_id_256fa396__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! -!../../../../../../node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!../../../../../../node_modules/vue-loader/dist/templateLoader.js??ruleSet[1].rules[2]!../../../../../../node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./list-clients.vue?vue&type=template&id=256fa396 */ "./node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/templateLoader.js??ruleSet[1].rules[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/src/views/menu/clients/list-clients.vue?vue&type=template&id=256fa396");


/***/ })

}]);