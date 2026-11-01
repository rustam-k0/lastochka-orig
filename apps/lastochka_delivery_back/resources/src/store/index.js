import Vue from "vue";
import Vuex from "vuex";
import actions from "./actions";

Vue.use(Vuex);
export default function createStore() {
  return new Vuex.Store({
    actions: actions,
    state() {
      return {
        _modals: [],
        status: {},
      };
    },
  });
}
