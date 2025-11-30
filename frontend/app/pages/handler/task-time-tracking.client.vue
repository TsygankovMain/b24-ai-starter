<script setup lang="ts">
import type { B24Frame } from '@bitrix24/b24jssdk'
import { ref, onMounted } from 'vue'

const { t, locales: localesI18n, setLocale } = useI18n()
const { $logger, initLang, processErrorGlobal } = useAppInit('TaskTimeTracking' as any)
const { $initializeB24Frame } = useNuxtApp()

const isLoading = ref(true)
const isSaving = ref(false)
const taskId = ref<string | null>(null)
const userId = ref<number | null>(null)

// Form data
const hours = ref<number | null>(null)
const description = ref<string>('')
const date = ref<string>(new Date().toISOString().split('T')[0])

// Alerts
const successMessage = ref<string | null>(null)
const errorMessage = ref<string | null>(null)

let $b24: B24Frame

onMounted(async () => {
  try {
    $b24 = await $initializeB24Frame()
    await initLang($b24, localesI18n, setLocale)

    // Cast to any because types might be missing info() method
    const placementInfo = ($b24.placement as any).info()
    taskId.value = placementInfo.options.taskId || (placementInfo.options as any).ID || null
    
    // Fallback: try to parse from placement options if it's a string (sometimes happens)
    if (!taskId.value && typeof placementInfo.options === 'string') {
        try {
            const parsed = JSON.parse(placementInfo.options)
            taskId.value = parsed.taskId || parsed.ID
        } catch (e) {
            // ignore
        }
    }

    // Get current user ID
    const authData = $b24.auth.getAuthData()
    if (authData) {
        // Cast to any because user_id might be missing in types
        userId.value = Number((authData as any).user_id)
    }

    if (!taskId.value) {
      errorMessage.value = 'Не удалось определить ID задачи.'
    }

    isLoading.value = false
  } catch (error: any) {
    processErrorGlobal(error)
    errorMessage.value = 'Ошибка инициализации: ' + error.message
    isLoading.value = false
  }
})

const saveTime = async () => {
  if (!taskId.value || !userId.value) {
    errorMessage.value = 'Ошибка: отсутствуют необходимые данные (ID задачи или пользователя).'
    return
  }

  if (!hours.value || hours.value <= 0) {
    errorMessage.value = 'Пожалуйста, укажите количество часов.'
    return
  }

  isSaving.value = true
  errorMessage.value = null
  successMessage.value = null

  try {
    const fields = {
      entityTypeId: 1164,
      fields: {
        title: `Отчет по задаче ${taskId.value!}`,
        ufCrm87_1761919581: taskId.value, // ID задачи
        ufCrm87_1761919601: userId.value, // Сотрудник
        ufCrm87_1761919617: hours.value, // Количество часов
        ufCrm87_1762026149771: description.value, // Описание
        ufCrm87_1764446274: date.value, // Дата отражения
        ufCrm87_1763717129: 'Y' // Учитываем? (Boolean/Enum - ставим Y по умолчанию)
      }
    }

    await $b24.callMethod('crm.item.add', fields)

    successMessage.value = 'Время успешно добавлено!'
    
    // Reset form
    hours.value = null
    description.value = ''
    date.value = new Date().toISOString().split('T')[0]

  } catch (error: any) {
    console.error('Save error:', error)
    errorMessage.value = 'Ошибка при сохранении: ' + (error.ex?.error_description || error.message)
  } finally {
    isSaving.value = false
  }
}
</script>

<template>
  <div class="p-4 bg-white min-h-screen">
    <div v-if="isLoading" class="flex justify-center items-center h-32">
      <B24Spinner size="md" color="primary" />
    </div>

    <div v-else>
      <h2 class="text-xl font-semibold mb-4 text-gray-800">Учет рабочего времени</h2>

      <B24Alert v-if="successMessage" color="success" class="mb-4">
        {{ successMessage }}
      </B24Alert>

      <B24Alert v-if="errorMessage" color="danger" class="mb-4">
        {{ errorMessage }}
      </B24Alert>

      <form @submit.prevent="saveTime" class="space-y-4 max-w-lg">
        
        <!-- Дата -->
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Дата</label>
          <input 
            v-model="date" 
            type="date" 
            class="w-full border-gray-300 rounded-md shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm p-2 border"
            required
          />
        </div>

        <!-- Часы -->
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Количество часов</label>
          <input 
            v-model.number="hours" 
            type="number" 
            step="0.1" 
            min="0.1"
            placeholder="Например, 2.5"
            class="w-full border-gray-300 rounded-md shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm p-2 border"
            required
          />
        </div>

        <!-- Описание -->
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Описание работ</label>
          <textarea 
            v-model="description" 
            rows="3" 
            placeholder="Что было сделано..."
            class="w-full border-gray-300 rounded-md shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm p-2 border"
          ></textarea>
        </div>

        <!-- Кнопка -->
        <div class="pt-2">
          <B24Button 
            type="submit" 
            color="success" 
            :loading="isSaving" 
            :disabled="isSaving || !taskId"
            size="md"
          >
            Сохранить время
          </B24Button>
        </div>

      </form>
      
      <div class="mt-8 text-xs text-gray-400">
        Task ID: {{ taskId }} | User ID: {{ userId }}
      </div>
    </div>
  </div>
</template>
