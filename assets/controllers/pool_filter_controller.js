import { Controller } from '@hotwired/stimulus';

/**
 * Submits the pool filter form whenever one of its selects changes, so the
 * list re-renders immediately with the selected type / radius / sort / page
 * size. The form is a plain GET form, which keeps the applied filters in the
 * query string (shareable and page-refresh safe).
 */
export default class extends Controller {
    submit() {
        this.element.submit();
    }
}
