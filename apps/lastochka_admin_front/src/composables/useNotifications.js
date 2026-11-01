import { ref, computed } from 'vue'
import SEND_ANNOUNCEMENT_NOTIFICATION from '@/graphql/announcements/sendNotification.graphql'

export function useNotifications() {
  const isLoading = ref(false)
  const error = ref(null)

  const sendAnnouncementNotification = async (apolloClient, announcementId, options = {}) => {
    try {
      isLoading.value = true
      error.value = null

      const variables = {
        announcementId: String(announcementId), // Преобразуем в строку для GraphQL ID!
        target: options.target || 'ALL_USERS'
      }

      // Добавляем segmentCriteria только если есть данные
      if (options.segmentCriteria && Object.keys(options.segmentCriteria).length > 0) {
        variables.segmentCriteria = options.segmentCriteria
      }

      const result = await apolloClient.admin.mutate({
        mutation: SEND_ANNOUNCEMENT_NOTIFICATION,
        variables
      })

      if (result?.data?.sendAnnouncementNotification?.success) {
        const response = result.data.sendAnnouncementNotification
        alert(`Успешно: ${response.message}`)
        return response
      }

      throw new Error(result?.data?.sendAnnouncementNotification?.message || 'Ошибка отправки уведомления')
    } catch (err) {
      const errorMessage = err.message || 'Не удалось отправить уведомление'
      error.value = errorMessage
      alert(`Ошибка: ${errorMessage}`)
      throw err
    } finally {
      isLoading.value = false
    }
  }

  return {
    isLoading: computed(() => isLoading.value),
    error: computed(() => error.value),
    sendAnnouncementNotification
  }
}