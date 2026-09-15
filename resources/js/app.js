import axios from 'axios';
import { createApp, computed, onMounted, ref } from 'vue/dist/vue.esm-bundler.js';
import '../css/app.css';

axios.defaults.withCredentials = true;
axios.defaults.withXSRFToken = true;

const App = {
  setup() {
    const user = ref(null);
    const email = ref('demo@example.com');
    const password = ref('password');
    const loginError = ref('');
    const loading = ref(false);
    const org = ref(null);
    const url = ref('');
    const reviews = ref([]);
    const pagination = ref({ current_page: 1, last_page: 1, total: 0 });
    const saveError = ref('');
    const saveMessage = ref('');
    let pollTimer = null;

    const isParsing = computed(() => ['queued', 'running'].includes(org.value?.status));
    const pageNumbers = computed(() => {
      const last = pagination.value.last_page || 1;
      const current = pagination.value.current_page || 1;
      const start = Math.max(1, current - 2);
      const end = Math.min(last, current + 2);
      return Array.from({ length: end - start + 1 }, (_, index) => start + index);
    });

    async function bootstrap() {
      try {
        const { data } = await axios.get('/api/user');
        user.value = data.user;
        if (user.value) {
          await loadOrganization();
        }
      } catch {
        user.value = null;
      }
    }

    async function login() {
      loginError.value = '';
      loading.value = true;

      try {
        await axios.get('/sanctum/csrf-cookie');
        const { data } = await axios.post('/api/login', { email: email.value, password: password.value });
        user.value = data.user;
        await loadOrganization();
      } catch (error) {
        loginError.value = error.response?.data?.message || 'Не удалось войти.';
      } finally {
        loading.value = false;
      }
    }

    async function logout() {
      await axios.post('/api/logout');
      user.value = null;
      org.value = null;
      reviews.value = [];
      window.clearInterval(pollTimer);
    }

    async function loadOrganization() {
      const { data } = await axios.get('/api/organization');
      org.value = data.organization;
      url.value = data.organization?.yandex_url || '';

      if (org.value?.status === 'ready') {
        await loadReviews(1);
      }

      schedulePolling();
    }

    async function saveOrganization() {
      saveError.value = '';
      saveMessage.value = '';
      loading.value = true;

      try {
        const { data } = await axios.post('/api/organization', { url: url.value });
        org.value = data.organization;
        reviews.value = [];
        saveMessage.value = 'Ссылка сохранена, парсинг поставлен в очередь.';
        schedulePolling();
      } catch (error) {
        saveError.value = error.response?.data?.message || Object.values(error.response?.data?.errors || {})?.[0]?.[0] || 'Не удалось сохранить ссылку.';
      } finally {
        loading.value = false;
      }
    }

    async function loadReviews(page = 1) {
      const { data } = await axios.get('/api/organization/reviews', { params: { page } });
      org.value = data.organization;
      reviews.value = data.reviews.data;
      pagination.value = {
        current_page: data.reviews.current_page,
        last_page: data.reviews.last_page,
        total: data.reviews.total,
      };
    }

    function schedulePolling() {
      window.clearInterval(pollTimer);

      if (!isParsing.value) {
        return;
      }

      pollTimer = window.setInterval(async () => {
        await loadOrganization();
        if (!isParsing.value && org.value?.status === 'ready') {
          await loadReviews(1);
        }
      }, 3000);
    }

    function formatDate(value) {
      if (!value) return 'Дата неизвестна';
      return new Intl.DateTimeFormat('ru-RU', { year: 'numeric', month: 'long', day: 'numeric' }).format(new Date(value));
    }

    onMounted(bootstrap);

    return {
      user,
      email,
      password,
      loginError,
      loading,
      org,
      url,
      reviews,
      pagination,
      saveError,
      saveMessage,
      isParsing,
      pageNumbers,
      login,
      logout,
      saveOrganization,
      loadReviews,
      formatDate,
    };
  },
  template: `
    <main class="shell">
      <section v-if="!user" class="auth-panel">
        <div class="intro">
          <p class="eyebrow">Тестовое задание</p>
          <h1>Отзывы организации из Яндекс.Карт</h1>
          <p>Вход под сид-пользователем, сохранение ссылки на карточку, фоновый парсинг отзывов и постраничный вывод результата.</p>
        </div>
        <form class="login-form" @submit.prevent="login">
          <label>
            Email
            <input v-model="email" autocomplete="email" type="email" required>
          </label>
          <label>
            Пароль
            <input v-model="password" autocomplete="current-password" type="password" required>
          </label>
          <p v-if="loginError" class="error">{{ loginError }}</p>
          <button :disabled="loading" type="submit">{{ loading ? 'Входим...' : 'Войти' }}</button>
        </form>
      </section>

      <section v-else class="workspace">
        <header class="topbar">
          <div>
            <p class="eyebrow">Настройки</p>
            <h1>Подключение карточки</h1>
          </div>
          <button class="secondary" type="button" @click="logout">Выйти</button>
        </header>

        <form class="settings" @submit.prevent="saveOrganization">
          <label>
            Ссылка на организацию в Яндекс.Картах
            <input v-model="url" placeholder="https://yandex.ru/maps/org/.../123456789/" required>
          </label>
          <button :disabled="loading" type="submit">{{ loading ? 'Сохраняем...' : 'Сохранить и обновить' }}</button>
        </form>
        <p v-if="saveError" class="error">{{ saveError }}</p>
        <p v-if="saveMessage" class="success">{{ saveMessage }}</p>

        <section v-if="org" class="summary-band">
          <div>
            <span>Статус</span>
            <strong>{{ org.status }}</strong>
            <progress max="100" :value="org.parse_progress"></progress>
          </div>
          <div>
            <span>Средний рейтинг</span>
            <strong>{{ org.rating || '...' }}</strong>
          </div>
          <div>
            <span>Оценок</span>
            <strong>{{ org.ratings_count }}</strong>
          </div>
          <div>
            <span>Отзывов</span>
            <strong>{{ org.reviews_count }}</strong>
          </div>
        </section>

        <p v-if="org?.last_error" class="error">{{ org.last_error }}</p>

        <section v-if="isParsing" class="empty-state">
          Идёт фоновый парсинг. Страница сама обновит статус и покажет отзывы после завершения.
        </section>

        <section v-if="reviews.length" class="reviews">
          <article v-for="review in reviews" :key="review.id" class="review">
            <div class="review-head">
              <div>
                <strong>{{ review.author_name || 'Аноним' }}</strong>
                <span>{{ formatDate(review.reviewed_at) }}</span>
              </div>
              <b>{{ review.rating }}/5</b>
            </div>
            <p>{{ review.text || 'Без текста' }}</p>
          </article>

          <nav class="pagination" aria-label="Навигация по отзывам">
            <button class="secondary" :disabled="pagination.current_page <= 1" @click="loadReviews(pagination.current_page - 1)">Назад</button>
            <button
              v-for="page in pageNumbers"
              :key="page"
              :class="{ active: page === pagination.current_page }"
              @click="loadReviews(page)"
            >{{ page }}</button>
            <button class="secondary" :disabled="pagination.current_page >= pagination.last_page" @click="loadReviews(pagination.current_page + 1)">Вперёд</button>
          </nav>
        </section>

        <section v-else-if="org?.status === 'ready'" class="empty-state">
          Отзывы не найдены, но карточка обработана.
        </section>
      </section>
    </main>
  `,
};

createApp(App).mount('#app');
