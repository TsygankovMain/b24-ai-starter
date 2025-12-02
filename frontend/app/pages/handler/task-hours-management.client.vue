<script setup lang="ts">
import type { B24Frame } from '@bitrix24/b24jssdk'
import { ref, onMounted, computed } from 'vue'

// ================================
// CONFIGURATION
// ================================
// TODO: В будущем вынести в отдельный конфигурационный модуль/composable
// для загрузки из настроек приложения или базы данных
const FIELD_CONFIG = {
  TASK_ID: 'ufCrm87_1761919581',
  EMPLOYEE: 'ufCrm87_1761919601',
  HOURS: 'ufCrm87_1761919617',
  IS_CONSIDERED: 'ufCrm87_1763717129',
  DESCRIPTION: 'ufCrm87_1762026149771',
  TASK_HIERARCHY_ID: 'ufCrm87_1764191110',
  TASK_HIERARCHY_TITLE: 'ufCrm87_1764191133',
}

// ================================
// INTERFACES
// ================================
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

interface FormData {
  hours: string
  description: string
  date: string
  employeeId: string
  targetTaskId: string | null
  isConsidered: boolean
}

// ================================
// COMPOSABLES & INITIALIZATION
// ================================
const { t, locales: localesI18n, setLocale } = useI18n()
const { $logger, initLang, processErrorGlobal } = useAppInit('TaskHoursManagement' as any)
const { $initializeB24Frame } = useNuxtApp()

// ================================
// STATE
// ================================
const isLoading = ref(true)
const error = ref<string | null>(null)
const taskTree = ref<TaskNode[]>([])
const users = ref<Record<string, string>>({})
const allUsers = ref<any[]>([])
const mainTaskId = ref<string | null>(null)
const currentUserId = ref<string | null>(null)
const updatingItemId = ref<string | null>(null)

// UI State
const openTaskIds = ref<Set<string>>(new Set())
const showModal = ref(false)
const showReportModal = ref(false)
const isCreating = ref(false)
const isReporting = ref(false)
const modalError = ref<string | null>(null)
const reportModalError = ref<string | null>(null)

// Settings State
const isSettingsOpen = ref(false)
const clientHourRate = ref(0)
const smartProcessId = ref(1164)

// Form State
const initialFormData: FormData = {
  hours: '',
  description: '',
  date: new Date().toISOString().split('T')[0],
  employeeId: '',
  targetTaskId: null,
  isConsidered: true
}
const formData = ref<FormData>({ ...initialFormData })

let $b24: B24Frame

// ================================
// COMPUTED
// ================================
const totalConsidered = computed(() => {
  return taskTree.value.reduce((sum, rootNode) => {
    return sum + (rootNode.cumulativeConsidered || 0)
  }, 0)
})

const totalUnconsidered = computed(() => {
  return taskTree.value.reduce((sum, rootNode) => {
    return sum + (rootNode.cumulativeUnconsidered || 0)
  }, 0)
})

const totalHours = computed(() => {
  return totalConsidered.value + totalUnconsidered.value
})

// ================================
// HELPER FUNCTIONS
// ================================
const callMethodPromise = (method: string, params: any = {}) => {
  return new Promise((resolve, reject) => {
    $b24.callMethod(method, params, (result: any) => {
      if (result.error()) {
        reject(result.error())
      } else {
        resolve(result.data())
      }
    })
  })
}

const callBatchPromise = (commands: any) => {
  return new Promise((resolve) => {
    $b24.callBatch(commands, (result: any) => resolve(result))
  })
}

// ================================
// DATA FETCHING FUNCTIONS
// ================================
const getTaskHierarchy = async (initialTaskId: string) => {
  let currentTaskId: string | null = initialTaskId
  const idPath: string[] = []
  const titlePath: string[] = []

  while (currentTaskId) {
    try {
      const result: any = await callMethodPromise('tasks.task.get', {
        taskId: currentTaskId,
        select: ['ID', 'TITLE', 'PARENT_ID']
      })
      const task = result.task

      if (task) {
        idPath.unshift(task.id)
        titlePath.unshift(task.title)

        if (task.parentId && task.parentId !== '0') {
          currentTaskId = task.parentId
        } else {
          currentTaskId = null
        }
      } else {
        currentTaskId = null
      }
    } catch (e) {
      console.error(`Ошибка при получении задачи ${currentTaskId}:`, e)
      currentTaskId = null
    }
  }
  return { idPath, titlePath }
}

const fetchData = async (currentTaskId: string) => {
  if (!smartProcessId.value) {
    error.value = 'ID Смарт-процесса не указан. Проверьте настройки.'
    isLoading.value = false
    return
  }

  isLoading.value = true
  error.value = null

  try {
    // 1. Получаем корневую задачу
    const rootTaskResult: any = await callMethodPromise('tasks.task.get', {
      taskId: currentTaskId,
      select: ['ID', 'TITLE']
    })
    const rootTaskData = rootTaskResult.task

    // 2. Итеративно собираем все подзадачи
    let allSubTasks: any[] = []
    let queue = [currentTaskId]
    const processedIds = new Set([currentTaskId])

    while (queue.length > 0) {
      const batchCmds = queue.map((id) => [
        'tasks.task.list',
        {
          filter: { PARENT_ID: id },
          select: ['id', 'title', 'parentId']
        }
      ])
      const batchResult: any = await callBatchPromise(batchCmds)

      queue = []

      for (const res of Object.values(batchResult)) {
        if (res && !res.error()) {
          const tasks = res.data().tasks || []
          for (const task of tasks) {
            if (!processedIds.has(task.id)) {
              allSubTasks.push(task)
              queue.push(task.id)
              processedIds.add(task.id)
            }
          }
        }
      }
    }

    const allTasks = [
      { id: rootTaskData.id, title: rootTaskData.title, parentId: null },
      ...allSubTasks
    ]
    const allTaskIds = allTasks.map((t) => t.id)

    // 3. Находим все связанные смарт-процессы
    const spBatchCmds = allTaskIds.map((taskId) => [
      'crm.item.list',
      {
        entityTypeId: smartProcessId.value,
        filter: { [FIELD_CONFIG.TASK_ID]: taskId },
        select: [
          'id',
          'title',
          'createdTime',
          FIELD_CONFIG.TASK_ID,
          FIELD_CONFIG.EMPLOYEE,
          FIELD_CONFIG.HOURS,
          FIELD_CONFIG.IS_CONSIDERED,
          FIELD_CONFIG.DESCRIPTION
        ]
      }
    ])
    const spResults: any = await callBatchPromise(spBatchCmds)
    const allItems = Object.values(spResults).flatMap((res: any) =>
      res && !res.error() && res.data().items ? res.data().items : []
    )

    const itemsByTaskId: Record<string, TimeRecord[]> = allItems.reduce(
      (acc: Record<string, TimeRecord[]>, item: any) => {
        const taskId = item[FIELD_CONFIG.TASK_ID]
        if (!acc[taskId]) acc[taskId] = []
        acc[taskId].push(item)
        return acc
      },
      {}
    )

    // 4. Строим узлы дерева с локальными часами
    const nodes: Record<string, TaskNode> = {}
    allTasks.forEach((task) => {
      const items = itemsByTaskId[task.id] || []
      nodes[task.id] = {
        taskId: task.id,
        taskTitle: task.title,
        parentId: task.parentId,
        items: items,
        totalConsidered: items.reduce((sum, item) => {
          const isConsidered =
            item[FIELD_CONFIG.IS_CONSIDERED] === true ||
            item[FIELD_CONFIG.IS_CONSIDERED] === 'Y'
          return sum + (isConsidered ? parseFloat(item[FIELD_CONFIG.HOURS]) || 0 : 0)
        }, 0),
        totalUnconsidered: items.reduce((sum, item) => {
          const isConsidered =
            item[FIELD_CONFIG.IS_CONSIDERED] === true ||
            item[FIELD_CONFIG.IS_CONSIDERED] === 'Y'
          return sum + (!isConsidered ? parseFloat(item[FIELD_CONFIG.HOURS]) || 0 : 0)
        }, 0),
        cumulativeConsidered: 0,
        cumulativeUnconsidered: 0,
        children: []
      }
    })

    // 5. Собираем иерархию
    const tree: TaskNode[] = []
    Object.values(nodes).forEach((node) => {
      if (node.parentId && nodes[node.parentId]) {
        nodes[node.parentId].children.push(node)
      } else if (String(node.taskId) === String(currentTaskId)) {
        tree.push(node)
      }
    })

    // 6. Рекурсивно вычисляем кумулятивные часы
    const calculateCumulativeTotals = (node: TaskNode) => {
      let childConsidered = 0
      let childUnconsidered = 0

      if (node.children && node.children.length > 0) {
        node.children.forEach((child) => {
          const childTotals = calculateCumulativeTotals(child)
          childConsidered += childTotals.considered
          childUnconsidered += childTotals.unconsidered
        })
      }

      node.cumulativeConsidered = (node.totalConsidered || 0) + childConsidered
      node.cumulativeUnconsidered = (node.totalUnconsidered || 0) + childUnconsidered

      return {
        considered: node.cumulativeConsidered,
        unconsidered: node.cumulativeUnconsidered
      }
    }

    tree.forEach(calculateCumulativeTotals)
    taskTree.value = tree

    // 7. Загружаем информацию о пользователях
    const employeeIds = [
      ...new Set(allItems.map((item: any) => item[FIELD_CONFIG.EMPLOYEE]).filter(Boolean))
    ]
    if (employeeIds.length > 0) {
      const userBatch = employeeIds.reduce(
        (acc: any, id: any) => ({ ...acc, [`user_${id}`]: ['user.get', { ID: id }] }),
        {}
      )
      const userResult: any = await callBatchPromise(userBatch)
      const usersData: Record<string, string> = {}
      employeeIds.forEach((id: any) => {
        const res = userResult[`user_${id}`]
        if (res && !res.error() && res.data()[0]) {
          const user = res.data()[0]
          usersData[id] = `${user.NAME} ${user.LAST_NAME}`
        } else {
          usersData[id] = `Пользователь #${id}`
        }
      })
      users.value = usersData
    }
  } catch (e: any) {
    console.error('Ошибка при загрузке данных:', e)
    error.value = e.message || 'Произошла неизвестная ошибка при загрузке данных.'
  } finally {
    isLoading.value = false
  }
}

// ================================
// ACTION HANDLERS
// ================================
const handleToggleConsidered = (itemId: string) => {
  updatingItemId.value = itemId

  // Поиск записи в дереве
  let itemToUpdate: TimeRecord | null = null
  const findItem = (nodes: TaskNode[]) => {
    for (const node of nodes) {
      const found = node.items.find((i) => i.id === itemId)
      if (found) {
        itemToUpdate = found
        return
      }
      if (node.children.length > 0) findItem(node.children)
    }
  }
  findItem(taskTree.value)

  if (!itemToUpdate) {
    error.value = 'Не удалось найти элемент для обновления.'
    updatingItemId.value = null
    return
  }

  const currentIsConsidered =
    itemToUpdate[FIELD_CONFIG.IS_CONSIDERED] === true ||
    itemToUpdate[FIELD_CONFIG.IS_CONSIDERED] === 'Y'

  $b24.callMethod(
    'crm.item.update',
    {
      entityTypeId: smartProcessId.value,
      id: itemId,
      fields: {
        [FIELD_CONFIG.IS_CONSIDERED]: currentIsConsidered ? 'N' : 'Y'
      }
    },
    (result: any) => {
      if (result.error()) {
        error.value = `Ошибка обновления: ${result.error().toString()}`
      }
      updatingItemId.value = null
      if (mainTaskId.value) {
        fetchData(mainTaskId.value)
      }
    }
  )
}

const handleCreateRecord = async () => {
  modalError.value = null

  if (!formData.value.hours || isNaN(Number(formData.value.hours)) || Number(formData.value.hours) <= 0) {
    modalError.value = 'Укажите корректное количество часов'
    return
  }
  if (!formData.value.description.trim()) {
    modalError.value = 'Укажите описание'
    return
  }
  if (!formData.value.date) {
    modalError.value = 'Укажите дату'
    return
  }
  if (!formData.value.employeeId) {
    modalError.value = 'Выберите сотрудника'
    return
  }
  if (!formData.value.targetTaskId) {
    modalError.value = 'Не выбрана задача для списания часов.'
    return
  }

  isCreating.value = true

  let idPath: string[] = []
  let titlePath: string[] = []

  try {
    const hierarchy = await getTaskHierarchy(formData.value.targetTaskId)
    idPath = hierarchy.idPath
    titlePath = hierarchy.titlePath
  } catch (e: any) {
    console.error('Ошибка при получении иерархии задачи:', e)
    modalError.value = `Не удалось получить иерархию задачи: ${e.message || e.toString()}`
    isCreating.value = false
    return
  }

  $b24.callMethod(
    'crm.item.add',
    {
      entityTypeId: smartProcessId.value,
      fields: {
        title: formData.value.description.substring(0, 255),
        [FIELD_CONFIG.HOURS]: parseFloat(formData.value.hours),
        [FIELD_CONFIG.IS_CONSIDERED]: formData.value.isConsidered ? 'Y' : 'N',
        [FIELD_CONFIG.TASK_ID]: formData.value.targetTaskId,
        [FIELD_CONFIG.EMPLOYEE]: formData.value.employeeId,
        [FIELD_CONFIG.DESCRIPTION]: formData.value.description,
        createdTime: formData.value.date + 'T00:00:00',
        [FIELD_CONFIG.TASK_HIERARCHY_ID]: idPath,
        [FIELD_CONFIG.TASK_HIERARCHY_TITLE]: titlePath
      }
    },
    (result: any) => {
      isCreating.value = false
      if (result.error()) {
        modalError.value = `Не удалось создать элемент: ${result.error().toString()}`
      } else {
        showModal.value = false
        if (mainTaskId.value) {
          fetchData(mainTaskId.value)
        }
      }
    }
  )
}

const handleTransferToReport = async () => {
  reportModalError.value = null

  if (totalConsidered.value <= 0) {
    reportModalError.value = 'Нет учитываемых часов для переноса'
    return
  }

  isReporting.value = true

  const itemsToTransfer: TimeRecord[] = []
  const collectItems = (nodes: TaskNode[]) => {
    nodes.forEach((node) => {
      node.items.forEach((item) => {
        const isConsidered =
          item[FIELD_CONFIG.IS_CONSIDERED] === true || item[FIELD_CONFIG.IS_CONSIDERED] === 'Y'
        if (isConsidered && (parseFloat(item[FIELD_CONFIG.HOURS]) || 0) > 0) {
          itemsToTransfer.push(item)
        }
      })
      if (node.children.length > 0) collectItems(node.children)
    })
  }
  collectItems(taskTree.value)

  if (itemsToTransfer.length === 0) {
    isReporting.value = false
    showReportModal.value = false
    return
  }

  try {
    // Bitrix24 batch имеет лимит ~50 команд, разбиваем на чанки
    const CHUNK_SIZE = 50
    const allCommands = itemsToTransfer.map((item) => {
      const hours = parseFloat(item[FIELD_CONFIG.HOURS]) || 0
      return [
        'task.elapseditem.add',
        {
          TASKID: item[FIELD_CONFIG.TASK_ID],
          FIELDS: {
            SECONDS: Math.round(hours * 3600),
            USER_ID: item[FIELD_CONFIG.EMPLOYEE] || currentUserId.value,
            COMMENT_TEXT:
              item[FIELD_CONFIG.DESCRIPTION] || item.title || `Списание ${hours.toFixed(2)} ч.`
          }
        }
      ]
    })

    // Разбиваем на чанки и выполняем последовательно
    for (let i = 0; i < allCommands.length; i += CHUNK_SIZE) {
      const chunk = allCommands.slice(i, i + CHUNK_SIZE)
      await callBatchPromise(chunk)
      
      // Небольшая задержка между чанками для разгрузки API
      if (i + CHUNK_SIZE < allCommands.length) {
        await new Promise(resolve => setTimeout(resolve, 100))
      }
    }

    isReporting.value = false
    showReportModal.value = false
    
    // Show notification
    if ($b24.parent) {
      $b24.parent.postMessage(
        {
          action: 'notification',
          message: `Успешно перенесено ${itemsToTransfer.length} записей в отчет.`
        },
        '*'
      )
    }
  } catch (error: any) {
    console.error('Ошибка переноса в отчет:', error)
    reportModalError.value = `Ошибка переноса: ${error.message || error.toString()}`
    isReporting.value = false
  }
}

const handleOpenModal = (targetTaskId: string) => {
  formData.value = {
    ...initialFormData,
    employeeId: currentUserId.value || '',
    targetTaskId: targetTaskId
  }
  modalError.value = null
  showModal.value = true
}

const toggleTaskGroup = (taskId: string) => {
  const newOpenTaskIds = new Set(openTaskIds.value)
  if (newOpenTaskIds.has(taskId)) {
    newOpenTaskIds.delete(taskId)
  } else {
    newOpenTaskIds.add(taskId)
  }
  openTaskIds.value = newOpenTaskIds
}

const handleOpenItem = (itemId: string) => {
  if ($b24.placement) {
    $b24.placement.call('openPath', [`/crm/type/${smartProcessId.value}/details/${itemId}/`])
  }
}

const formatDate = (dateStr: string) => {
  return dateStr ? new Date(dateStr).toLocaleDateString('ru-RU') : 'Не указана'
}

// ================================
// LIFECYCLE
// ================================
onMounted(async () => {
  try {
    $b24 = await $initializeB24Frame()
    await initLang($b24, localesI18n, setLocale)

    // Получаем taskId из placement
    const placementInfo = ($b24.placement as any).info()
    let currentTaskId: string | null = null

    if (placementInfo?.options) {
      let options = placementInfo.options
      if (typeof options === 'string') {
        try {
          options = JSON.parse(options)
        } catch (e) {
          options = {}
        }
      }
      currentTaskId = options.ID || options.taskId || options.id
    }

    if (!currentTaskId) {
      error.value = 'Не удалось получить ID задачи.'
      isLoading.value = false
      return
    }

    mainTaskId.value = currentTaskId
    openTaskIds.value = new Set([currentTaskId])

    // Получаем текущего пользователя и всех пользователей
    $b24.callBatch(
      {
        currentUser: ['user.current', {}],
        allUsers: [
          'user.get',
          {
            FILTER: { ACTIVE: 'Y' },
            sort: 'LAST_NAME',
            order: 'ASC'
          }
        ]
      },
      (result: any) => {
        const currentUserRes = result.currentUser
        if (currentUserRes && !currentUserRes.error()) {
          const user = currentUserRes.data()
          currentUserId.value = user.ID
          formData.value.employeeId = user.ID
        }

        const allUsersRes = result.allUsers
        if (allUsersRes && !allUsersRes.error()) {
          allUsers.value = allUsersRes.data()
        }

        fetchData(currentTaskId!)
      }
    )
  } catch (e: any) {
    processErrorGlobal(e)
    error.value = 'Ошибка инициализации: ' + e.message
    isLoading.value = false
  }
})
</script>

<template>
  <div class="h-full flex flex-col bg-slate-50">
    <!-- Loading State -->
    <div v-if="isLoading" class="flex items-center justify-center h-full p-6">
      <div class="text-center">
        <B24Spinner size="lg" color="primary" />
        <p class="mt-4 text-slate-600">Загрузка данных о задаче и времени...</p>
      </div>
    </div>

    <!-- Error State -->
    <div v-else-if="error" class="flex items-center justify-center h-full p-4">
      <div class="max-w-lg w-full">
        <B24Alert color="danger">
          <strong>Ошибка:</strong> {{ error }}
        </B24Alert>
      </div>
    </div>

    <!-- Main Content -->
    <template v-else>
      <!-- Header -->
      <header class="p-4 bg-white border-b space-y-4 shrink-0">
        <!-- Settings Panel (Collapsible) -->
        <B24Card variant="outline">
          <div class="cursor-pointer" @click="isSettingsOpen = !isSettingsOpen">
            <div class="flex items-center justify-between p-3">
              <div class="flex items-center gap-2">
                <span class="text-slate-600">⚙️</span>
                <span class="font-semibold text-slate-800">Настройки расчета и данных</span>
              </div>
              <span class="text-slate-500">{{ isSettingsOpen ? '▲' : '▼' }}</span>
            </div>
          </div>

          <div v-if="isSettingsOpen" class="border-t p-4 bg-white grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-medium text-slate-700 mb-1">
                Стоимость часа для клиента (руб.)
              </label>
              <input
                v-model.number="clientHourRate"
                type="number"
                placeholder="Например: 3000"
                class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
            </div>
            <div>
              <label class="block text-sm font-medium text-slate-700 mb-1">ID Смарт-процесса</label>
              <input
                v-model.number="smartProcessId"
                type="number"
                placeholder="Например: 1164"
                class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
            </div>
          </div>
        </B24Card>

        <!-- Statistics Cards -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
          <!-- Total Hours -->
          <div class="p-3 bg-white rounded-lg border border-slate-200 shadow-sm">
            <p class="text-xs text-slate-500 mb-1">Всего по задаче</p>
            <p class="text-2xl font-bold text-blue-600">{{ totalHours.toFixed(2) }}<span class="text-sm text-slate-500 ml-1">ч</span></p>
          </div>
          
          <!-- Billable Hours -->
          <div class="p-3 bg-white rounded-lg border border-slate-200 shadow-sm">
            <p class="text-xs text-slate-500 mb-1">Учитываемые</p>
            <p class="text-2xl font-bold text-green-600">{{ totalConsidered.toFixed(2) }}<span class="text-sm text-slate-500 ml-1">ч</span></p>
          </div>
          
          <!-- Non-billable Hours -->
          <div class="p-3 bg-white rounded-lg border border-slate-200 shadow-sm">
            <p class="text-xs text-slate-500 mb-1">Не учитываемые</p>
            <p class="text-2xl font-bold text-red-600">{{ totalUnconsidered.toFixed(2) }}<span class="text-sm text-slate-500 ml-1">ч</span></p>
          </div>
          
          <!-- Actions -->
          <div class="flex gap-2">
            <B24Button 
              label="Отразить" 
              color="success" 
              size="sm"
              @click="handleOpenModal(mainTaskId!)"
              class="flex-1"
            />
            <B24Button 
              label="В отчет" 
              color="primary" 
              size="sm"
              :disabled="totalConsidered <= 0"
              @click="showReportModal = true"
              class="flex-1"
            />
          </div>
        </div>
      </header>

      <!-- Main Content Area -->
      <main class="flex-1 overflow-y-auto p-4">
        <div v-if="taskTree.length > 0 && (taskTree[0].cumulativeConsidered > 0 || taskTree[0].cumulativeUnconsidered > 0)" class="max-w-7xl mx-auto space-y-4">
          <!-- Recursive Task Groups -->
          <TaskGroupComponent 
            v-for="rootTask in taskTree" 
            :key="rootTask.taskId" 
            :task="rootTask" 
            :level="0"
            :client-hour-rate="clientHourRate"
            :open-task-ids="openTaskIds"
            :updating-item-id="updatingItemId"
            :users="users"
            :field-config="FIELD_CONFIG"
            @toggle-group="toggleTaskGroup"
            @open-modal="handleOpenModal"
            @toggle-considered="handleToggleConsidered"
            @open-item="handleOpenItem"
          />
        </div>
        
        <!-- Empty State -->
        <div v-else class="text-center py-16">
          <div class="text-6xl mb-4">⏱️</div>
          <p class="text-lg font-semibold text-slate-700 mb-2">Нет отметок времени</p>
          <p class="text-sm text-slate-500 mb-4">Нажмите "Отразить", чтобы добавить первую запись.</p>
          <B24Button label="Отразить часы" color="primary" @click="handleOpenModal(mainTaskId!)" />
        </div>
      </main>

      <!-- Modal: Create Time Record -->
      <div v-if="showModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
        <div class="bg-white rounded-lg shadow-lg max-w-md w-full p-6">
          <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-semibold text-slate-900">Отразить часы для задачи #{{ formData.targetTaskId }}</h3>
            <button @click="showModal = false" :disabled="isCreating" class="text-slate-500 hover:text-slate-700">✕</button>
          </div>

          <B24Alert v-if="modalError" color="danger" class="mb-4">
            {{ modalError }}
          </B24Alert>

          <div class="space-y-4">
            <div>
              <label class="block text-sm font-medium text-slate-700 mb-1">Сотрудник <span class="text-red-500">*</span></label>
              <select 
                v-model="formData.employeeId" 
                :disabled="isCreating" 
                class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
              >
                <option value="">-- Выберите сотрудника --</option>
                <option v-for="user in allUsers" :key="user.ID" :value="user.ID">
                  {{ user.NAME }} {{ user.LAST_NAME }}
                </option>
              </select>
            </div>

            <div>
              <label class="block text-sm font-medium text-slate-700 mb-1">Количество часов <span class="text-red-500">*</span></label>
              <input 
                v-model="formData.hours" 
                type="number" 
                step="0.5" 
                min="0" 
                :disabled="isCreating" 
                placeholder="Например: 8" 
                class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
            </div>

            <div>
              <label class="block text-sm font-medium text-slate-700 mb-1">Описание <span class="text-red-500">*</span></label>
              <textarea 
                v-model="formData.description" 
                :disabled="isCreating" 
                placeholder="Опишите выполненную работу" 
                rows="3" 
                class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
            </div>

            <div>
              <label class="block text-sm font-medium text-slate-700 mb-1">Дата отражения <span class="text-red-500">*</span></label>
              <input 
                v-model="formData.date" 
                type="date" 
                :disabled="isCreating" 
                class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
            </div>

            <div class="flex items-center">
              <input 
                id="isConsidered" 
                v-model="formData.isConsidered" 
                type="checkbox" 
                :disabled="isCreating" 
                class="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500"
              />
              <label for="isConsidered" class="ml-2 block text-sm text-slate-900">Учитываемые часы</label>
            </div>
          </div>

          <div class="flex gap-3 mt-6">
            <B24Button label="Отмена" color="secondary" :disabled="isCreating" @click="showModal = false" class="flex-1" />
            <B24Button label="Сохранить" color="success" :loading="isCreating" :disabled="isCreating" @click="handleCreateRecord" class="flex-1" />
          </div>
        </div>
      </div>

      <!-- Modal: Transfer to Report -->
      <div v-if="showReportModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
        <div class="bg-white rounded-lg shadow-lg max-w-md w-full p-6">
          <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-semibold text-slate-900">Перенести часы в отчет</h3>
            <button @click="showReportModal = false" :disabled="isReporting" class="text-slate-500 hover:text-slate-700">✕</button>
          </div>

          <B24Alert v-if="reportModalError" color="danger" class="mb-4">
            {{ reportModalError }}
          </B24Alert>

          <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
            <p class="text-sm text-blue-900"><span class="font-semibold">Сумма для переноса:</span></p>
            <p class="text-3xl font-bold text-blue-600 mt-2">{{ totalConsidered.toFixed(2) }} ч</p>
            <p class="text-xs text-blue-700 mt-2">Будут созданы отдельные записи времени для каждой метки с учитываемыми часами.</p>
          </div>

          <p class="text-sm text-slate-600 mb-6">
            Это действие создаст записи в отчете времени Bitrix24, но <strong>не изменит</strong> данные в этом приложении.
          </p>

          <div class="flex gap-3">
            <B24Button label="Отмена" color="secondary" :disabled="isReporting" @click="showReportModal = false" class="flex-1" />
            <B24Button label="Перенести" color="primary" :loading="isReporting" :disabled="isReporting" @click="handleTransferToReport" class="flex-1" />
          </div>
        </div>
      </div>
    </template>
  </div>
</template>
