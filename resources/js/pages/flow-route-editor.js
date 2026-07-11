export default function flowRouteEditor(initialPoints = [], placementRules = {}) {
    const normalizedPoints = initialPoints.map((point) => ({
        id: Number(point.id),
        type: String(point.type),
    }))

    return {
        pointModal: null,
        addPointModal: null,
        draggedPointId: null,
        initialPointOrder: normalizedPoints.map((point) => point.id),
        pointOrder: normalizedPoints.map((point) => point.id),
        pointTypes: Object.fromEntries(normalizedPoints.map((point) => [point.id, point.type])),
        placementRules,
        invalidDropTargetId: null,
        invalidDropMessage: null,
        orderChanged: false,

        openPoint(pointId) {
            this.pointModal = Number(pointId)
        },

        closePoint() {
            this.pointModal = null
        },

        openAddPoint(capabilityId) {
            this.addPointModal = Number(capabilityId)
        },

        closeAddPoint() {
            this.addPointModal = null
        },

        startDrag(event, pointId) {
            this.draggedPointId = Number(pointId)
            this.clearInvalidDropPreview()
            event.dataTransfer.effectAllowed = 'move'
            event.dataTransfer.setData('text/plain', String(pointId))
        },

        dragOver(event, targetPointId) {
            event.preventDefault()

            const draggedId = this.draggedPointId
            const targetId = Number(targetPointId)

            if (! draggedId || draggedId === targetId) {
                return
            }

            const list = this.$refs.pointList
            const dragged = list?.querySelector(`[data-point-id="${draggedId}"]`)
            const target = list?.querySelector(`[data-point-id="${targetId}"]`)

            if (! dragged || ! target) {
                return
            }

            const targetRect = target.getBoundingClientRect()
            const insertAfter = event.clientY > targetRect.top + (targetRect.height / 2)
            const candidateOrder = this.candidateOrderForDrop(draggedId, targetId, insertAfter)
            const placementError = this.placementError(candidateOrder)

            if (placementError) {
                this.invalidDropTargetId = targetId
                this.invalidDropMessage = this.concisePlacementMessage(candidateOrder, placementError)
                return
            }

            this.clearInvalidDropPreview()

            if (insertAfter) {
                target.after(dragged)
            } else {
                target.before(dragged)
            }

            this.syncOrderFromDom()
        },

        endDrag() {
            this.draggedPointId = null
            this.clearInvalidDropPreview()
            this.syncOrderFromDom()
        },

        clearInvalidDropPreview(targetPointId = null) {
            if (targetPointId !== null && this.invalidDropTargetId !== Number(targetPointId)) {
                return
            }

            this.invalidDropTargetId = null
            this.invalidDropMessage = null
        },

        concisePlacementMessage(order, fallbackMessage) {
            if (order.length === 0) {
                return fallbackMessage
            }

            const lastPointId = order[order.length - 1]
            const lastPointType = this.pointTypes[lastPointId]
            const waitRule = this.placementRules.wait ?? {}

            if (lastPointType === waitRule.type) {
                return 'Wait cannot be the last Point.'
            }

            return fallbackMessage
        },

        candidateOrderForDrop(draggedId, targetId, insertAfter) {
            const order = this.pointOrder.filter((pointId) => pointId !== draggedId)
            let targetIndex = order.indexOf(targetId)

            if (targetIndex === -1) {
                return this.pointOrder
            }

            if (insertAfter) {
                targetIndex += 1
            }

            order.splice(targetIndex, 0, draggedId)

            return order
        },

        canMove(pointId, direction) {
            const candidateOrder = this.pointOrder.slice()
            const currentIndex = candidateOrder.indexOf(Number(pointId))
            const targetIndex = currentIndex + Number(direction)

            if (currentIndex === -1 || targetIndex < 0 || targetIndex >= candidateOrder.length) {
                return false
            }

            ;[candidateOrder[currentIndex], candidateOrder[targetIndex]] = [
                candidateOrder[targetIndex],
                candidateOrder[currentIndex],
            ]

            return ! this.placementError(candidateOrder)
        },

        canRemove(pointId) {
            const candidateOrder = this.pointOrder.filter((candidateId) => candidateId !== Number(pointId))

            return ! this.placementError(candidateOrder)
        },

        removalError(pointId) {
            const candidateOrder = this.pointOrder.filter((candidateId) => candidateId !== Number(pointId))

            return this.placementError(candidateOrder, 'remove')
        },

        handleRemove(event, pointId) {
            if (! this.canRemove(pointId)) {
                event.preventDefault()
                return
            }

            if (! window.confirm('Remove this Point from the active Route?')) {
                event.preventDefault()
            }
        },

        placementError(order, operation = 'reorder') {
            if (order.length === 0) {
                return null
            }

            const lastPointId = order[order.length - 1]
            const lastPointType = this.pointTypes[lastPointId]
            const waitRule = this.placementRules.wait ?? {}
            const changeStatusRule = this.placementRules.change_status ?? {}

            if (lastPointType === waitRule.type) {
                return operation === 'remove'
                    ? waitRule.remove_message ?? waitRule.message ?? 'Wait cannot be the final Point.'
                    : waitRule.message ?? 'Wait cannot be the final Point.'
            }

            const nonTerminalStatusChange = order
                .slice(0, -1)
                .some((pointId) => this.pointTypes[pointId] === changeStatusRule.type)

            if (nonTerminalStatusChange) {
                return operation === 'remove'
                    ? changeStatusRule.remove_message ?? changeStatusRule.message ?? 'Change Status must be the final Point.'
                    : changeStatusRule.message ?? 'Change Status must be the final Point.'
            }

            return null
        },

        syncOrderFromDom() {
            const list = this.$refs.pointList

            if (! list) {
                return
            }

            this.pointOrder = Array.from(list.querySelectorAll('[data-point-id]'))
                .map((element) => Number(element.dataset.pointId))

            this.orderChanged = JSON.stringify(this.pointOrder) !== JSON.stringify(this.initialPointOrder)
        },
    }
}
