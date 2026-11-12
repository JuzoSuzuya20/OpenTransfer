<script setup>
import { ref, onMounted, defineExpose, inject, computed } from 'vue'
import { Check, X, RotateCcw } from 'lucide-vue-next'
import { getEmailTemplates, updateEmailTemplates, resetEmailTemplate, getSettingById, saveSettingsById } from '../../api'
import { domData } from '../../domData'

import { useToast } from 'vue-toastification'
import { mapSettings } from '../../utils'

import { useTranslate } from '@tolgee/vue'
import EmailTemplateEditor from '../emailTemplateEditor.vue'
import OtSelect from '../layout/OtSelect.vue'

// The line the seeder installs, replaced by its translation in every e-mail
const SHIPPED_FALLBACK = "If you're having trouble with the button above, copy and paste the URL below into your web browser."
import { useConfirmDialog } from '../../composables/useConfirmDialog'

const { t } = useTranslate()

const showHelpTip = inject('showHelpTip')

const toast = useToast()

const saving = ref(false)
const emailTemplates = ref([])
const originalTemplates = ref({}) // Store original state for dirty checking
const fallbackText = ref('')
const originalFallbackText = ref('')

const emit = defineEmits(['navItemClicked'])

// Each email is written in the language of its reader: the admin rewrites one language at a time
const defaultLanguage = domData().default_language || 'en'
const locale = ref(defaultLanguage)
const LANGUAGES = [
  { code: 'en', name: 'English' },
  { code: 'fr', name: 'Français' },
  { code: 'de', name: 'Deutsch' },
  { code: 'es', name: 'Español' },
  { code: 'it', name: 'Italiano' },
  { code: 'nl', name: 'Nederlands' },
  { code: 'pt', name: 'Português' }
]

const loadTemplates = async () => {
  try {
    const templates = await getEmailTemplates(locale.value)
    emailTemplates.value = []
    originalTemplates.value = {}
    templates.forEach((template) => {
      emailTemplates.value.push({
        name: tidyTemplateName(template.name),
        id: template.id,
        content: template.content,
        variables: template.variables,
        subject: template.subject,
        customised: template.customised,
        requiredVariables: template.requiredVariables || []
      })
      originalTemplates.value[template.id] = JSON.stringify({ subject: template.subject, variables: template.variables })
    })
  } catch (error) {
    console.error(error)
    toast.error(t.value('settings.emailTemplates.error_loading_templates'))
  }
}

const languageOptions = LANGUAGES.map((language) => ({ value: language.code, label: language.name, lang: language.code }))

// The select shows the language being edited until the change is confirmed
const confirmDialog = useConfirmDialog()
const changeLanguage = async (code) => {
  if (
    emailTemplates.value.some(isTemplateDirty) &&
    !(await confirmDialog.show({
    title: t.value('dialog.title'),
    message: t.value('emails.admin.unsaved'),
    okText: t.value('dialog.change_anyway'),
    cancelText: t.value('dialog.cancel'),
    tone: 'danger'
  }))
  ) {
    return
  }
  locale.value = code
  await loadTemplates()
}

const resetTemplate = async (template) => {
  const sure = await confirmDialog.show({
    title: t.value('dialog.title'),
    message: t.value('emails.admin.reset_confirm', { name: template.name }),
    okText: t.value('emails.admin.reset'),
    cancelText: t.value('dialog.cancel'),
    tone: 'danger'
  })
  if (!sure) return
  try {
    await resetEmailTemplate(locale.value, template.id)
    toast.success(t.value('emails.admin.reset_done'))
    await loadTemplates()
  } catch (error) {
    toast.error(t.value('emails.admin.reset_failed'))
  }
}

onMounted(async () => {
  await loadTemplates()
  try {
    const text = await getSettingById('email_template_fallback_text')
    // The line installed with the app shows as empty: the field's placeholder is its translation,
    // and the e-mails send the translation (EmailTexts::layout)
    const value = text.value === SHIPPED_FALLBACK ? '' : text.value
    fallbackText.value = value
    originalFallbackText.value = value
  } catch (error) {
    console.error(error)
  }
})

const tidyTemplateName = (name) => {
  // First, remove 'Mail' or 'mail' at the end (case-insensitive)
  let result = name.replace(/[mM]ail$/, '')

  // Replace underscores with spaces
  result = result.replace(/_/g, ' ')

  // Insert spaces before capital letters in camelCase
  result = result.replace(/([a-z])([A-Z])/g, '$1 $2')

  // Capitalize first letter of each word
  result = result.replace(/\b\w/g, (char) => char.toUpperCase())

  return result
}

// Check if a template is dirty (has unsaved changes)
const isTemplateDirty = (template) => {
  const current = JSON.stringify({
    subject: template.subject,
    variables: template.variables
  })
  return current !== originalTemplates.value[template.id]
}

// Check if a specific variable is valid (non-empty string)
const isVariableValid = (template, variableName) => {
  if (variableName === 'subject') {
    return template.subject && template.subject.trim() !== ''
  }
  return template.variables[variableName] && template.variables[variableName].trim() !== ''
}

// Validate a single template - returns array of invalid required variable names
const getTemplateErrors = (template) => {
  const errors = []
  for (const varName of template.requiredVariables || []) {
    if (!isVariableValid(template, varName)) {
      errors.push(varName)
    }
  }
  return errors
}

// Check if template is valid (all required variables have values)
const isTemplateValid = (template) => {
  return getTemplateErrors(template).length === 0
}

const saveEmailTemplates = async () => {
  // Get dirty templates only
  const dirtyTemplates = emailTemplates.value.filter(isTemplateDirty)
  
  // Validate dirty templates before saving
  const invalidTemplates = []
  for (const template of dirtyTemplates) {
    const errors = getTemplateErrors(template)
    if (errors.length > 0) {
      invalidTemplates.push({ template, errors })
    }
  }
  
  if (invalidTemplates.length > 0) {
    // Show error for each invalid template
    for (const { template, errors } of invalidTemplates) {
      const errorFields = errors.join(', ')
      toast.error(t.value('settings.emailTemplates.validation_error', { 
        template: template.name, 
        fields: errorFields 
      }))
    }
    return
  }
  
  // Save dirty templates
  if (dirtyTemplates.length > 0) {
    try {
      await updateEmailTemplates({ locale: locale.value, templates: dirtyTemplates })
      // Update original state after successful save
      for (const template of dirtyTemplates) {
        originalTemplates.value[template.id] = JSON.stringify({
          subject: template.subject,
          variables: template.variables
        })
        template.customised = true
      }
      toast.success(t.value('settings.emailTemplates.success'))
    } catch (error) {
      console.error(error)
      toast.error(t.value('settings.emailTemplates.error_saving_templates'))
    }
  }
  
  // Save fallback text if changed
  if (fallbackText.value !== originalFallbackText.value) {
    try {
      await saveSettingsById({ email_template_fallback_text: fallbackText.value })
      originalFallbackText.value = fallbackText.value
    } catch (error) {
      console.error(error)
      toast.error(t.value('settings.emailTemplates.error_saving_fallback'))
    }
  }
  
  if (dirtyTemplates.length === 0 && fallbackText.value === originalFallbackText.value) {
    toast.info(t.value('settings.emailTemplates.no_changes'))
  }
}

const handleNavItemClicked = (item) => {
  emit('navItemClicked', item)
}

const updateTemplate = (event, template) => {
  emailTemplates.value = emailTemplates.value.map((t) => {
    if (t.id === template.id) {
      return template
    }
    return t
  })
}

// Format variable name for display
// The label the editor shows for that block (subject, header, content, action_url, action_text)
const formatVariableName = (name) => {
  const label = t.value(`settings.emailTemplates.sections.${name}`, '')
  return label && label !== `settings.emailTemplates.sections.${name}` ? label : name.replace(/_/g, ' ')
}

//define exposed methods
defineExpose({
  saveEmailTemplates
})
</script>
<template>
  <div class="email-templates-page">
    <!-- One link per email: the page is long -->
    <nav class="section-index" :aria-label="$t('settings.title.emailTemplates')">
      <a href="#baseTemplate" class="ot-act" v-if="locale === defaultLanguage" @click.prevent="handleNavItemClicked('baseTemplate')">
        {{ $t('settings.emailTemplates.baseTemplate') }}
      </a>
      <a v-for="template in emailTemplates" :key="template.id" :href="`#${template.id}`" class="ot-act" @click.prevent="handleNavItemClicked(template.id)">
        {{ $t(template.name) }}
      </a>
    </nav>

    <div class="admin-sections">
      <section class="setting-group" id="emailLanguage" aria-labelledby="h-email-language">
        <div class="setting-group-header">
          <h3 id="h-email-language">{{ $t('emails.admin.language_title') }}</h3>
        </div>
        <div class="setting-group-body email-language">
          <div class="setting-group-body-item">
            <label for="email-language">{{ $t('emails.admin.language_label') }}</label>
            <OtSelect id="email-language" :model-value="locale" :options="languageOptions" @change="changeLanguage" />
          </div>
          <p class="card-description">{{ $t('emails.admin.language_help') }}</p>
        </div>
      </section>

      <section class="setting-group" id="baseTemplate" v-if="locale === defaultLanguage" aria-labelledby="h-base-template">
        <div class="setting-group-header">
          <h3 id="h-base-template">{{ $t('settings.emailTemplates.baseTemplate') }}</h3>
        </div>
        <div class="setting-group-body">
          <p class="card-description">{{ $t('settings.emailTemplates.baseTemplateDescription') }}</p>
          <div class="setting-group-body-item">
            <label for="baseTemplateFallbackText">{{ $t('settings.emailTemplates.sections.fallbackText') }}</label>
            <input type="text" id="baseTemplateFallbackText" v-model="fallbackText" :placeholder="$t('settings.emailTemplates.fallback_default')" />
            <p class="help-text">{{ $t('settings.emailTemplates.sections.fallbackTextDescription') }}</p>
          </div>
        </div>
      </section>

      <section class="setting-group email-template" v-for="template in emailTemplates" :key="template.id" :id="template.id" :aria-labelledby="`h-${template.id}`">
        <div class="setting-group-header email-template-head">
          <h3 :id="`h-${template.id}`">{{ $t(template.name) }}</h3>
          <span class="ot-pill" :class="{ 'is-warning': template.customised }">
            {{ template.customised ? $t('emails.admin.customised') : $t('emails.admin.shipped') }}
          </span>
          <button type="button" class="ot-act email-template-reset" v-if="template.customised" @click="resetTemplate(template)">
            <RotateCcw aria-hidden="true" />
            {{ $t('emails.admin.reset') }}
          </button>
        </div>

        <div class="email-template-body">
          <EmailTemplateEditor :model-value="template" @update:model-value="updateTemplate($event, template)" />
          <!-- What the email cannot go without, checked as the admin types -->
          <aside class="email-template-required" :aria-label="$t('settings.emailTemplates.requiredFields')">
            <h4>{{ $t('settings.emailTemplates.requiredFields') }}</h4>
            <ul class="required-variables-list">
              <li
                v-for="varName in template.requiredVariables"
                :key="varName"
                :class="{ valid: isVariableValid(template, varName), invalid: !isVariableValid(template, varName) }"
              >
                <Check v-if="isVariableValid(template, varName)" class="status-icon ot-check-pop" aria-hidden="true" />
                <X v-else class="status-icon" aria-hidden="true" />
                {{ formatVariableName(varName) }}
              </li>
            </ul>
          </aside>
        </div>
      </section>
    </div>
  </div>
</template>

<style scoped lang="scss">
.setting-group .email-language {
  display: grid;
  grid-template-columns: minmax(0, 320px) minmax(0, 1fr);
  gap: 12px 32px;
  align-items: end;

  @media (max-width: 760px) {
    grid-template-columns: minmax(0, 1fr);
  }
}
.email-template-head {
  flex-direction: row !important;
  flex-wrap: wrap;
  align-items: center !important;
  gap: 10px;

  h3 {
    flex: 1;
  }
  .ot-act {
    margin: 0;
  }
}
// The editor takes the width; what the email cannot go without stays beside it
.email-template-body {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 200px;
  gap: 24px;
  align-items: start;

  @media (max-width: 900px) {
    grid-template-columns: minmax(0, 1fr);
  }
}
.email-template-required {
  position: sticky;
  top: 16px;
  padding: 12px 14px;
  background: var(--ot-field);
  border: 1px solid var(--ot-line-soft);
  border-radius: var(--ot-radius);

  h4 {
    margin: 0 0 8px;
    font-size: 0.75rem;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--ot-muted);
  }
}
.required-variables-list {
  display: flex;
  flex-direction: column;
  gap: 4px;
  margin: 0;
  padding: 0;
  list-style: none;
  font-size: 0.8125rem;

  li {
    display: flex;
    align-items: center;
    gap: 6px;

    &.valid {
      color: var(--ot-success);
    }
    &.invalid {
      color: var(--ot-danger-text);
      font-weight: 700;
    }
  }
  .status-icon {
    width: 14px;
    height: 14px;
    flex-shrink: 0;
  }
}
</style>
