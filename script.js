const { createApp } = Vue;

createApp({
  data() {
    return {
      cityInput: "",
      selectedCity: "",
      history: "Search for a city to load its story.",
      images: [
        "https://via.placeholder.com/900x600?text=City+Image",
        "https://via.placeholder.com/900x600?text=City+Image"
      ],
      loading: false,
      locationLoading: false,
      eventsLoading: false,
      chatLoading: false,
      errorMessage: "",
      eventsError: "",
      statusText: "Search manually or use your current location.",
      events: [],
      chatInput: "",
      chatMessages: []
    };
  },
  computed: {
    mapUrl() {
      return this.selectedCity
        ? `https://www.google.com/maps?q=${encodeURIComponent(this.selectedCity)}&output=embed`
        : "";
    }
  },
  methods: {
    async searchCity() {
      const city = this.cityInput.trim();

      if (!city) {
        this.errorMessage = "Enter a city name first.";
        return;
      }

      this.selectedCity = city;
      this.loading = true;
      this.errorMessage = "";
      this.eventsError = "";
      this.history = "Loading city history...";
      this.images = [
        "https://via.placeholder.com/900x600?text=Loading",
        "https://via.placeholder.com/900x600?text=Loading"
      ];
      this.chatMessages = [];
      this.statusText = `Loading data for ${city}...`;

      try {
        const response = await fetch(`getCityData.php?city=${encodeURIComponent(city)}`);
        const data = await response.json();

        if (data.error) {
          throw new Error(data.error);
        }

        let resolvedData = data;
        if (this.shouldUseBrowserFallback(data)) {
          resolvedData = await this.fetchCityDataInBrowser(city);
        }

        this.history = resolvedData.history || "No history found.";
        this.images = [
          resolvedData.img1 || this.images[0],
          resolvedData.img2 || this.images[1]
        ];
        this.statusText = `Showing results for ${city}.`;
      } catch (error) {
        try {
          const fallbackData = await this.fetchCityDataInBrowser(city);
          this.history = fallbackData.history || "No history found.";
          this.images = [
            fallbackData.img1 || this.images[0],
            fallbackData.img2 || this.images[1]
          ];
          this.statusText = `Showing results for ${city}.`;
        } catch (fallbackError) {
          this.errorMessage = fallbackError.message || error.message || "Failed to load city data.";
          this.history = "City information could not be loaded.";
          this.statusText = "The city data request failed.";
        }
      } finally {
        this.loading = false;
      }

      this.loadEvents(city);
    },
    async useCurrentLocation() {
      if (!navigator.geolocation) {
        this.errorMessage = "Geolocation is not supported in this browser.";
        return;
      }

      this.locationLoading = true;
      this.errorMessage = "";
      this.statusText = "Detecting your location...";

      try {
        const position = await new Promise((resolve, reject) => {
          navigator.geolocation.getCurrentPosition(resolve, reject, {
            enableHighAccuracy: true,
            timeout: 10000
          });
        });

        const { latitude, longitude } = position.coords;
        const geoResponse = await fetch(
          `https://nominatim.openstreetmap.org/reverse?lat=${latitude}&lon=${longitude}&format=json&accept-language=en`
        );
        const geoData = await geoResponse.json();

        const city =
          geoData.address?.city ||
          geoData.address?.town ||
          geoData.address?.village ||
          geoData.address?.state ||
          geoData.address?.country ||
          "";

        if (!city) {
          throw new Error("Could not detect a city from your location.");
        }

        this.cityInput = city;
        this.statusText = `Location found: ${city}.`;
        await this.searchCity();
      } catch (error) {
        this.errorMessage = error.message || "Failed to use current location.";
        this.statusText = "Location request failed.";
      } finally {
        this.locationLoading = false;
      }
    },
    async loadEvents(city) {
      this.eventsLoading = true;
      this.eventsError = "";
      this.events = [];

      try {
        const response = await fetch(`getEvents.php?city=${encodeURIComponent(city)}`);
        const data = await response.json();

        if (data.events && Array.isArray(data.events)) {
          this.events = data.events;
        } else if (data.error) {
          this.eventsError = data.error;
        }
      } catch (error) {
        this.eventsError = "Events could not be loaded.";
      } finally {
        this.eventsLoading = false;
      }
    },
    shouldUseBrowserFallback(data) {
      const placeholder = "https://via.placeholder.com/";
      return (
        !data ||
        data.history === "No history available." ||
        !data.history ||
        (typeof data.img1 === "string" && data.img1.includes(placeholder) &&
          typeof data.img2 === "string" && data.img2.includes(placeholder))
      );
    },
    async fetchCityDataInBrowser(city) {
      const searchUrl = `https://en.wikipedia.org/w/api.php?action=query&generator=search&gsrsearch=${encodeURIComponent(
        city
      )}&gsrlimit=1&prop=extracts|pageimages&exintro=1&explaintext=1&piprop=original|thumbnail&pithumbsize=1200&format=json&origin=*`;

      const response = await fetch(searchUrl);
      const data = await response.json();
      const pages = Object.values(data.query?.pages || {});
      const page = pages[0];

      if (!page) {
        throw new Error("No city data found from Wikipedia.");
      }

      const img1 = page.original?.source || page.thumbnail?.source || "https://via.placeholder.com/900x600?text=No+Image";
      const img2 = page.thumbnail?.source || page.original?.source || img1;

      return {
        history: page.extract || "No history found.",
        img1,
        img2
      };
    },
    async askChatbot() {
      const message = this.chatInput.trim();

      if (!message || this.chatLoading) {
        return;
      }

      this.chatMessages.push({ role: "user", text: message });
      this.chatInput = "";
      this.chatLoading = true;

      try {
        const response = await fetch("chatbot.php", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({
            message: this.selectedCity
              ? `${message}\n\nCity context: ${this.selectedCity}`
              : message
          })
        });
        const data = await response.json();

        this.chatMessages.push({
          role: "bot",
          text: data.reply || "No response received."
        });
      } catch (error) {
        this.chatMessages.push({
          role: "bot",
          text: "Error getting a response from the assistant."
        });
      } finally {
        this.chatLoading = false;
      }
    }
  }
}).mount("#app");
