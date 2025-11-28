import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { useBitrixReport, type ReportFilter, type ReportItem } from '#imports'

export const useReportsStore = defineStore('reports', () => {
    const { fetchReportData } = useBitrixReport()

    const items = ref<ReportItem[]>([])
    const isLoading = ref(false)
    const error = ref<string | null>(null)

    // Current Filter State
    const currentFilter = ref<ReportFilter>({})

    const fetchReports = async (filter: ReportFilter) => {
        isLoading.value = true
        error.value = null
        currentFilter.value = filter

        try {
            const { data } = await fetchReportData(filter)
            if (data.value) {
                items.value = data.value.items
            }
        } catch (e: any) {
            error.value = e.message || 'Failed to fetch reports'
        } finally {
            isLoading.value = false
        }
    }

    // Getters for specific reports could go here
    // For example, grouping by Employee -> Project -> Task

    const totalItems = computed(() => items.value.length)

    return {
        items,
        isLoading,
        error,
        currentFilter,
        fetchReports,
        totalItems
    }
})
