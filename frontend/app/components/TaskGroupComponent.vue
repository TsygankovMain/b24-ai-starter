<script setup lang="ts">
import type { Ref } from 'vue'

interface TimeRecord {
  id: string
  title: string
  createdTime: string
  [key: string]: any
}

interface TaskNode {
  taskId: string
  taskTitle: string
  parentId: string | null
  items: TimeRecord[]
  totalConsidered: number
  totalUnconsidered: number
  cumulativeConsidered: number
  cumulativeUnconsidered: number
  children: TaskNode[]
}

const props = defineProps<{
  task: TaskNode
  level: number
  clientHourRate: number
  openTaskIds: Set<string>
  updatingItemId: string | null
  users: Record<string, string>
  fieldConfig: {
    EMPLOYEE: string
    HOURS: string
    IS_CONSIDERED: string
    DESCRIPTION: string
  }
}>()

const emit = defineEmits<{
  (e: 'toggleGroup', taskId: string): void
  (e: 'openModal', taskId: string): void
  (e: 'toggleConsidered', itemId: string): void
  (e: 'openItem', itemId: string): void
}>()

const formatDate = (dateStr: string) => {
  return dateStr ? new Date(dateStr).toLocaleDateString('ru-RU') : 'Не указана'
}

const isOpen = (taskId: string) => {
  return props.openTaskIds.has(taskId)
}

const clientSum = computed(() => {
  return props.task.cumulativeConsidered * props.clientHourRate
})
</script>

<template>
  <div :style="{ marginLeft: level > 0 ? '1rem' : '0' }">
    <B24Card variant="outline" class="overflow-hidden">
      <!-- Task Header -->
      <div class="p-3 bg-slate-50 border-b flex justify-between items-center">
        <div class="flex-1 min-w-0 cursor-pointer" @click="emit('toggleGroup', task.taskId)">
          <h3 class="text-sm font-bold text-slate-900 truncate">
            <span v-if="level > 0" class="font-normal text-purple-600">[Подзадача] </span>
            {{ task.taskTitle }}
          </h3>
          <p class="text-xs text-slate-600 mt-1">ID: {{ task.taskId }}</p>
        </div>

        <div class="flex items-center gap-4 ml-4 text-right shrink-0">
          <!-- Client Sum (if rate is set) -->
          <div v-if="clientHourRate > 0" class="border-r pr-4 border-slate-200">
            <p class="text-xs text-blue-600">Сумма для клиента</p>
            <p class="text-sm font-bold text-slate-800">
              {{ clientSum.toLocaleString('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }} руб.
            </p>
          </div>

          <!-- Considered Hours -->
          <div>
            <p class="text-xs text-green-600">Учтено (всего)</p>
            <p class="text-sm font-bold text-slate-800">{{ task.cumulativeConsidered.toFixed(2) }} ч</p>
            <p v-if="task.children.length > 0 && task.totalConsidered > 0" class="text-xs text-slate-500 italic">
              в т.ч. своих: {{ task.totalConsidered.toFixed(2) }} ч
            </p>
          </div>

          <!-- Unconsidered Hours -->
          <div>
            <p class="text-xs text-red-600">Не учтено (всего)</p>
            <p class="text-sm font-bold text-slate-800">{{ task.cumulativeUnconsidered.toFixed(2) }} ч</p>
            <p v-if="task.children.length > 0 && task.totalUnconsidered > 0" class="text-xs text-slate-500 italic">
              в т.ч. своих: {{ task.totalUnconsidered.toFixed(2) }} ч
            </p>
          </div>

          <!-- Actions -->
          <div class="flex flex-col items-center gap-1">
            <button
              @click.stop="emit('openModal', task.taskId)"
              title="Отразить часы для этой задачи"
              class="p-1 rounded-full bg-green-100 text-green-700 hover:bg-green-200"
            >
              ➕
            </button>
            <button
              @click.stop="emit('toggleGroup', task.taskId)"
              title="Развернуть/Свернуть"
              class="p-1 rounded-full hover:bg-slate-200 transition-transform"
              :class="{ 'rotate-180': isOpen(task.taskId) }"
            >
              ▼
            </button>
          </div>
        </div>
      </div>

      <!-- Task Content (Items + Children) -->
      <div v-if="isOpen(task.taskId)">
        <!-- Time Record Items -->
        <div v-for="item in task.items" :key="item.id" class="p-3 border-t transition-opacity" :class="{ 'opacity-50': updatingItemId === item.id }">
          <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-2">
            <div class="flex-1 min-w-0">
              <p class="font-semibold text-slate-800 truncate hover:text-blue-600 cursor-pointer" @click="emit('openItem', item.id)" :title="item.title">
                {{ item.title || 'Без названия' }}
              </p>
              <div class="flex items-center text-xs text-slate-500 mt-2 gap-3">
                <div class="flex items-center">
                  👤 {{ users[item[fieldConfig.EMPLOYEE]] || 'Неизвестно' }}
                </div>
                <div class="flex items-center">
                  📅 {{ formatDate(item.createdTime) }}
                </div>
              </div>
            </div>

            <div class="flex md:flex-col items-center md:items-end justify-between mt-2 md:mt-0 gap-2">
              <div class="flex items-center gap-2 text-sm font-bold">
                <span
                  :class="item[fieldConfig.IS_CONSIDERED] === true || item[fieldConfig.IS_CONSIDERED] === 'Y' ? 'text-green-600' : 'text-red-600'"
                  :title="item[fieldConfig.IS_CONSIDERED] === true || item[fieldConfig.IS_CONSIDERED] === 'Y' ? 'Учитываемые' : 'Не учитываемые'"
                >
                  {{ (parseFloat(item[fieldConfig.HOURS]) || 0).toFixed(2) }}ч
                </span>
              </div>
              <B24Button
                label="Переключить"
                size="xs"
                color="secondary"
                :loading="updatingItemId === item.id"
                :disabled="updatingItemId === item.id"
                @click="emit('toggleConsidered', item.id)"
              />
            </div>
          </div>
        </div>

        <!-- Child Tasks (Recursive) -->
        <div v-if="task.children.length > 0" class="p-2 space-y-2 bg-slate-50 border-t">
          <TaskGroupComponent
            v-for="childTask in task.children"
            :key="childTask.taskId"
            :task="childTask"
            :level="level + 1"
            :client-hour-rate="clientHourRate"
            :open-task-ids="openTaskIds"
            :updating-item-id="updatingItemId"
            :users="users"
            :field-config="fieldConfig"
            @toggle-group="emit('toggleGroup', $event)"
            @open-modal="emit('openModal', $event)"
            @toggle-considered="emit('toggleConsidered', $event)"
            @open-item="emit('openItem', $event)"
          />
        </div>
      </div>
    </B24Card>
  </div>
</template>
