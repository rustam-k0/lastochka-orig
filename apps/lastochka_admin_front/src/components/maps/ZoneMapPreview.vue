<template>
  <div class="zone-map-preview">
    <div ref="mapContainer" class="zone-map-preview__map"></div>
  </div>
</template>

<script>
import L from "leaflet";
import "leaflet/dist/leaflet.css";

export default {
  name: "ZoneMapPreview",
  props: {
    coordinates: {
      type: Array,
      required: true,
    },
  },
  data() {
    return {
      map: null,
    };
  },
  mounted() {
    this.$nextTick(() => {
      setTimeout(() => this.initMap(), 100);
    });
  },
  beforeUnmount() {
    if (this.map) {
      this.map.remove();
      this.map = null;
    }
  },
  methods: {
    initMap() {
      const latLngs = this.coordinates
        .map((c) => [parseFloat(c.latitude), parseFloat(c.longitude)])
        .filter(([lat, lng]) => !isNaN(lat) && !isNaN(lng));

      if (!latLngs.length) return;

      this.map = L.map(this.$refs.mapContainer, {
        zoomControl: true,
        attributionControl: true,
      });

      this.map.attributionControl.setPrefix(false);

      L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
        attribution: "&copy; OpenStreetMap contributors",
      }).addTo(this.map);

      const polygon = L.polygon(latLngs, {
        color: "#3388ff",
        weight: 2,
        fillOpacity: 0.2,
      }).addTo(this.map);

      this.map.fitBounds(polygon.getBounds(), { padding: [20, 20] });
    },
  },
};
</script>

<style lang="stylus">
.zone-map-preview
  margin-top 12px
  width 100%

  &__map
    width 100%
    height 300px
    border-radius 8px
    border 1px solid var(--gray-3)
</style>
