import { getOwner } from '@ember/application';
import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { isBlank } from '@ember/utils';
import { inject as service } from '@ember/service';
import FullCalendar from '@fullcalendar/core';  
import dayGridPlugin from '@fullcalendar/daygrid';
import interactionPlugin from '@fullcalendar/interaction'; 

export default class OrderScheduleCardComponent extends Component {
    @service calendar;
    @service store;
    @service contextPanel;
    @service intl;
    @service eventBus;
    @service modalsManager;
    @service notifications;
    @service abilities;
    @tracked timezone = Intl.DateTimeFormat().resolvedOptions().timeZone;
    @tracked isAssigningDriver;
    @tracked drivers = [];
    @tracked isLoadingDrivers = false;
    @tracked assignedDrivers = [];
    @tracked orders = [];
    @tracked events = [];
    @tracked isModalOpen = false;

    router = null;
    calendarInstance = null; 

    constructor(owner, { order }) {
        super(...arguments);
        let ownerInstance = getOwner(this);
        let storeService = ownerInstance.lookup('service:store');

        // Try looking up router:main as an alternative
        this.router = ownerInstance.lookup('router:main');
       
        this.loadDriverFromOrder(order);
        this.loadPayloadFromOrder(order);
    }
    
    get effectiveEventBus() {
        return this.eventBus || this.args.eventBus;
    }
    
    @action loadDrivers(orderUuid) {
        this.isLoadingDrivers = true;
        try {
            this.drivers = this.store.query('driver', { 
                order_uuid: orderUuid,
                timezone: this.timezone
            });
        } catch (error) {
            console.error('Failed to load drivers:', error);
            this.drivers = [];
        } finally {
            this.isLoadingDrivers = false;
        }
    }
    
    @action onClickDriver(driver) {
        this.contextPanel.focus(driver);
    }

    @action onClickVehicle(vehicle) {
        this.contextPanel.focus(vehicle);
    }

    @action startAssignDriver() { 
        if (this.abilities.cannot('fleet-ops assign-driver-for order')) {
            return;
        }
        this.isAssigningDriver = !this.isAssigningDriver;
        if (this.isAssigningDriver) {
            const order = this.args.order;
            this.loadDrivers(order.id);
        }
    }
   
    
    @action assignDriver(driver) {
        const eventBus = this.effectiveEventBus;
        try {
            // Close any open modals first
            document.querySelectorAll('.modal.show').forEach(modal => {
                const closeBtn = modal.querySelector('.close, .btn-close, [data-dismiss="modal"]');
                if (closeBtn) {
                    closeBtn.click();
                }
            });
        } catch (e) {
            console.error('Error closing modals:', e);
        }
        
        if (this.isModalOpen) {
            // Prevent opening a new modal if one is already open
            return;
        }
        
        const order = this.args.order;
        this.modalsManager.done().then(() => {
            // setTimeout(() => {
                this.isModalOpen = true; // Mark that a modal is being shown 
               
                // If driver is not selected, confirm to unassign the driver
                if (isBlank(driver)) { 
                    return this.modalsManager.confirm({ 
                        title: this.intl.t('fleet-ops.component.order.schedule-card.unassign-driver'),
                        body: this.intl.t('fleet-ops.component.order.schedule-card.unassign-text', { orderId: order.public_id }),
                        acceptButtonText: this.intl.t('fleet-ops.component.order.schedule-card.unassign-button'),
                        confirm: async (modal) => {
                            order.setProperties({
                                driver_assigned: null,
                                is_driver_assigned: false,
                                driver_assigned_uuid: null,
                                vehicle_assigned: null,
                            });
    
                            modal.startLoading();
    
                            try {
                                await order.save();
                                
                                // Get the current page from the router
                                const currentRoute = this.router.currentRoute;
                                const queryParams = currentRoute.queryParams || {};
                                const currentPage = queryParams.page || 1;
                                
                                // Update the ref timestamp while keeping the same page
                                const newQueryParams = {
                                    ref: Date.now(),
                                    page: currentPage
                                };
                                
                                return this.router.transitionTo('console.fleet-ops.operations.scheduler.index', {
                                    queryParams: newQueryParams
                                }).then(() => {
                                    if (eventBus) {
                                        eventBus.publish('calendar-refresh-needed', { 
                                            orderId: order.id,
                                            currentPage: currentPage,
                                            refreshAll: true
                                        });
                                    }
                                    this.notifications.success(
                                        this.intl.t('fleet-ops.operations.scheduler.index.success-message', { 
                                            orderId: order.public_id, 
                                            orderAt: order.scheduledAt
                                        })
                                    );
                                    modal.done();
                                    this.isModalOpen = false;
                                });
                            } catch (error) { 
                                this.notifications.serverError(error);
                                modal.stopLoading();
                            } finally {
                                this.isModalOpen = false;
                            }
                        },
                        decline: (modal) => {
                            this.isAssigningDriver = false;
                            modal.done();
                            this.isModalOpen = false; 
                        },
                    });
                } else {
                    // If driver is available, assign the driver to the order
                    return this.modalsManager.confirm({
                        title: this.intl.t('fleet-ops.component.order.schedule-card.assign-driver'),
                        body: driver.is_available
                            ? this.intl.t('fleet-ops.component.order.schedule-card.assign-text', {
                                driverName: driver.name,
                                orderId: order.public_id,
                            })
                            : this.intl.t('fleet-ops.component.order.schedule-card.assign-busy-text', {
                                driverName: driver.name,
                                orderId: order.public_id,
                                availability: driver.availability_message,
                                button: driver.button_message,
                            }),
                        acceptButtonText: driver.is_available
                            ? this.intl.t('fleet-ops.component.order.schedule-card.assign-button')
                            : this.intl.t('fleet-ops.component.order.schedule-card.assign-busy-button', {
                                button: driver.button_message,
                            }),
    
                        confirm: (modal) => {
                            // Get the current query params
                            const currentRoute = this.router.currentRoute;
                            const queryParams = currentRoute.queryParams || {};
                            const currentPage = queryParams.page || 1;
                            modal.startLoading();
                            order.setProperties({
                                driver_assigned: driver,
                                is_driver_assigned: true,
                                driver_assigned_uuid: driver.id,
                                vehicle_assigned: driver.vehicle || null,
                            });
    
                            return order.save()
                                .then(() => {
                                    this.isAssigningDriver = false;
                                    
                                    
                                    // Update with current page
                                    const newQueryParams = {
                                        ref: Date.now(),
                                        page: currentPage
                                    };
                                    
                                    return this.router.transitionTo('console.fleet-ops.operations.scheduler.index', {
                                        queryParams: newQueryParams
                                    });
                                })
                                .catch((error) => {
                                    this.notifications.serverError(error);
                                })
                                .finally(() => {
                                    if (eventBus) {
                                        // Pass the current page to the refresh handler
                                        eventBus.publish('calendar-refresh-needed', { 
                                            orderId: order.id,
                                            currentPage: currentPage,
                                            refreshAll: true
                                        });
                                    } else {
                                        console.error("eventBus is not available.");
                                    }
                                    this.notifications.success(
                                        this.intl.t('fleet-ops.operations.scheduler.index.success-message', { 
                                            orderId: order.public_id, 
                                            orderAt: order.scheduledAt 
                                        })
                                    );
                                    modal.done();
                                    this.isModalOpen = false;
                                });
                        },
                        decline: (modal) => {
                            modal.done();
                            this.isModalOpen = false;
                        },
                    });
                }
            // }, 100);
        });
    }

    @action onTitleClick(order) {
        const { onTitleClick } = this.args;

        if (typeof onTitleClick === 'function') {
            onTitleClick(order);
        }
    }

    loadDriverFromOrder(order) { 
        if (order && typeof order.loadDriver === 'function') {
            order.loadDriver();
        }
    }

    loadPayloadFromOrder(order) { 
        if (order && typeof order.loadPayload === 'function') {
            order.loadPayload();
        }
    }
}