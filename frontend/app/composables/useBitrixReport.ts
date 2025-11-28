import { useFetch } from '#app'

export interface ReportFilter {
    dateFrom?: string
    dateTo?: string
    employeeId?: string
    projectName?: string
}

export interface ReportItem {
    id: number
    taskId: string
    taskName: string
    projectName: string
    hierarchyIds: string[]
    hierarchyTitles: string[]
    hours: number
    type: 'Учитываемые' | 'Неучитываемые'
    date: string
    employeeId: string
}

export const useBitrixReport = () => {
    const config = useRuntimeConfig()
    const baseUrl = config.public.apiBase || '/api' // Adjust based on your proxy setup

    const fetchReportData = async (filter: ReportFilter) => {
        const { data, error, pending } = await useFetch<{ items: ReportItem[], count: number }>('/api/reports/data', {
            baseURL: baseUrl,
            method: 'GET',
            params: filter,
        })

        if (error.value) {
            console.error('Error fetching report data:', error.value)
            throw error.value
        }

        return { data, pending }
    }

    return {
        fetchReportData
    }
}
