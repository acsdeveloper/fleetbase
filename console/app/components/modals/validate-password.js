import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';

export default class ModalsValidatePasswordComponent extends Component {
    @service fetch;
    @service notifications;
    @service intl;
    @tracked options = {};
    @tracked password;
    @tracked confirmPassword;

    constructor(owner, { options }) {
        super(...arguments);
        this.options = options;
        this.setupOptions();
    }

    setupOptions() {
        this.options.title = this.intl.t('fleet-ops.user-management.validate-current-password');
        this.options.acceptButtonText = this.intl.t('fleet-ops.user-management.validate-password');
        this.options.declineButtonHidden = true;
        this.options.confirm = (modal) => {
            modal.startLoading();
            return this.validatePassword.perform();
        };
    }

    @task *validatePassword() {
        let isPasswordValid = false;

        try {
            yield this.fetch.post('users/validate-password', {
                password: this.password,
                password_confirmation: this.confirmPassword,
            });

            isPasswordValid = true;
        } catch (error) {
            this.notifications.serverError(error, 'Invalid current password.');
        }

        if (typeof this.options.onValidated === 'function') {
            this.options.onValidated(isPasswordValid);
        }

        return isPasswordValid;
    }
}
