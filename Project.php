<?php

namespace App\Models;

use App\Lib\Db\ActiveRecord;
use App\Lib\Db\ActiveRecordCollection;
use App\Models\Attributes\CreatedAtAttribute;
use App\Models\Attributes\UpdatedAtAttribute;
use App\Models\Attributes\DeadlineDateAttribute;
use App\Models\Attributes\NameAttribute;
use App\Models\Attributes\ContactsAttributes;
use App\Models\Attributes\AccessTokenAttribute;
use App\Models\Attributes\StatusAttribute;
use App\Models\Behaviors\HasAccessBehavior;
use App\Models\Relations\AdminUserRelation;
use App\Models\Relations\ClientRelation;
use App\Models\Behaviors\DataBehavior;
use App\Models\Behaviors\SettingsBehavior;
use App\Lib\Decorators\ModelHtml;
use App\Validations\Messages;
use yii\base\Exception;
use yii\validators\UrlValidator;
use yii\web\ForbiddenHttpException;

/**
 * @method Decorators\ProjectHtml html()
 * @package App\Models
 */
class Project extends ActiveRecord
{
    use CreatedAtAttribute;
    use UpdatedAtAttribute;
    use DeadlineDateAttribute {
        isExpired as isDeadlineExpired;
    }
    use AccessTokenAttribute;
    use NameAttribute;
    use ContactsAttributes;
    use StatusAttribute;
    use AdminUserRelation;
    use ClientRelation;
    use DataBehavior;
    use SettingsBehavior;
    use ModelHtml;
    use HasAccessBehavior;

    // Статусы
    // Проект не запущен
    const STATUS_NOT_STARTED = 0;
    // Проект начат
    const STATUS_STARTED = 1;
    // Прокт остановлен
    const STATUS_STOPPED = 2;
    // Проект завершён
    const STATUS_FINISHED = 3;

    /* настройки проекта */
    const SETTING_OPENED = 'opened'; // «Открытый» -- это значит, что в проекте можно регистрироваться по прямой ссылке
    const SETTING_WITH_DEADLINE = 'withDeadline'; // Наличие у проекта deadline-а
    const SETTING_SHOW_RESULTS = 'showResults'; // показывать ли кандидату результат
    const SETTING_REDIRECT_SUCCESS = 'redirectSuccess'; // редирект для удачного исхода
    const SETTING_REDIRECT_FAIL = 'redirectFail'; // редирект для "плохого" исхода
    const SETTING_STATE_PARAMS = 'stateParams'; // переменные состояния
    const SETTING_FORM_CANDIDATE_ONLY = 'presetFormCandidateOnly'; // переменные состояния

    /**
     * @var array
     */
    protected $_testsIds;

    /**
     * @var Test[] Тесты, которые надо связать с проектом
     */
    protected $_saveTests;

    /**
     * @var array
     */
    protected $_formsIds;

    /**
     * @var Form[] Формы, которые надо связать с проектом
     */
    protected $_saveForms;

    /**
     * @return string
     */
    public static function tableName()
    {
        return '{{%projects}}';
    }

    /**
     * @return array Список названий статусов по ID статуса
     */
    public static function statusNames()
    {
        return [
            static::STATUS_NOT_STARTED => 'Не запущен',
            static::STATUS_STARTED     => 'Запущен',
            static::STATUS_STOPPED     => 'Остановлен',
            static::STATUS_FINISHED    => 'Завершён',
        ];
    }

    /**
     * @return array
     */
    public function rules()
    {
        return array_merge(
            $this->getNameAttributeRules(),
//            $this->getContactsAttributesRules(),
            $this->getDeadlineDateAttributeRules([
                'deadlineDate' => [
                    'when' => function () {
                        return $this->getWithDeadline();
                    }
                ]
            ]),
            [
                ['presetFormId', 'validatePresetFormId', 'skipOnEmpty' => false],
                ['presetFormCandidateOnly', 'boolean', 'when' => function (Project $model) {
                    return $model->getPresetFormId();
                }],

                ['testsIds', 'validateTestsIds', 'skipOnEmpty' => false],
                ['formsIds', 'safe'],

                ['letterSubject', 'required', 'message' => Messages::REQUIRED],
                ['letterSubject', 'string', 'max' => 100, 'tooLong' => Messages::TOO_LONG,],
                ['letterTemplate', 'required', 'message' => Messages::REQUIRED],
                ['letterTemplate', 'string', 'max' => 10000, 'tooLong' => Messages::TOO_LONG,],

                [['isOpened', 'withDeadline', 'showResultsToCandidate'], 'boolean'],

                ['accessToken', 'match', 'pattern' => '/^[\w\-]+$/', 'message' => 'Можно использвать только латинские буквы и цифры'],
                ['accessToken', 'string', 'max' => 32, 'tooLong' => Messages::TOO_LONG],
                ['accessToken', 'unique', 'targetAttribute' => 'access_token', 'message' => 'Часть ссылки уже используется'],

                ['redirectSuccess', 'url', 'skipOnEmpty' => true, 'message' => Messages::WRONG_URL],
                ['redirectFail', 'url', 'skipOnEmpty' => true, 'message' => Messages::WRONG_URL],
                ['stateParams', 'validateStateParams', 'skipOnEmpty' => true],
            ]
        );
    }

    /**
     * @inheritdoc
     */
    public function init()
    {
        parent::init();

        $this->initDataBehavior();

        $this->onBeforeInsert([$this, 'generateAndSetAccessToken']);

        // Сохранение связей проекта с тестами после сохранения проекта
        $this->onAfterInsert([$this, 'saveTests']);
        $this->onAfterUpdate([$this, 'saveTests']);
        $this->onAfterValidate(function () {
            if (!$this->getWithDeadline()) {
                $this->setDeadlineDate(null);
            }
        });

        /** удалить всё что нужно сопроводительного */
        $this->onBeforeDelete(function ($event) {
            if (!$this->isEmpty()) {
                throw new ForbiddenHttpException('Проект в котором есть кандидаты не может быть удалён');
            }

            ProjectTest::deleteAll(['project_id' => $this->getId()]);
            return true;
        });
    }

    /**
     * Есть ли доступ у указнного польззователя в данном проекте указанные права доступа
     *
     * @param User $user
     * @param $permission
     *
     * @return bool
     */
    protected function _hasAccess(User $user, $permission)
    {
        switch ($permission) {
            case Permission::PROJECTS_VIEW:
            case Permission::PROJECTS_STORE:
            case Permission::PROJECTS_EXPORT_RAW:
            case Permission::PROJECTS_USERS_STORE:
            case Permission::PROJECTS_USERS_SEND_ACCESS_LETTER:
                return $this->getClient()->hasAccess($user, Permission::CLIENTS_VIEW);
                break;
            default:
                throw new \RuntimeException(sprintf('Тип прав `%s` не указан', $permission));
        }
    }

    /**
     * @return bool
     */
    public function isExpired()
    {
        return $this->getWithDeadline() && $this->isDeadlineExpired();
    }

    /**
     * @return bool
     */
    public function isStatusNotStarted()
    {
        return $this->checkStatus(static::STATUS_NOT_STARTED);
    }

    /**
     * @return bool
     */
    public function isStatusStarted()
    {
        return $this->checkStatus(static::STATUS_STARTED);
    }

    /**
     * @return bool
     */
    public function isStatusStopped()
    {
        return $this->checkStatus(static::STATUS_STOPPED);
    }

    /**
     * @return bool
     */
    public function isStatusFinished()
    {
        return $this->checkStatus(static::STATUS_FINISHED);
    }

    /**
     * Пуст ли проект
     *
     * @return bool
     */
    public function isEmpty()
    {
        return $this->getCandidatesCount() == 0;
    }

    /**
     * @return bool Открытый ли проект
     */
    public function getIsOpened()
    {
        return $this->getSetting(static::SETTING_OPENED, false);
    }

    /**
     * @param bool $isOpened
     */
    public function setIsOpened($isOpened)
    {
        $this->setSetting(static::SETTING_OPENED, (bool)$isOpened);
    }

    /**
     * @return bool
     */
    public function getWithDeadline()
    {
        return $this->getSetting(static::SETTING_WITH_DEADLINE, false);
    }

    /**
     * @param bool
     */
    public function setWithDeadline($withDeadline)
    {
        $this->setSetting(static::SETTING_WITH_DEADLINE, (bool)$withDeadline);
    }

    /**
     * Получить настройку показа результата кандидатам
     *
     * @return mixed
     */
    public function getShowResultsToCandidate()
    {
        return $this->getSetting(static::SETTING_SHOW_RESULTS, false);
    }

    /**
     * Установить настройку показа результата кандидатам
     *
     * @param bool|mixed $showResults
     */
    public function setShowResultsToCandidate($showResults)
    {
        $this->setSetting(static::SETTING_SHOW_RESULTS, (bool)$showResults);
    }

    /**
     * Получить настройку параметров состояний
     *
     * @return mixed
     */
    public function getPresetFormCandidateOnly()
    {
        return $this->getSetting(static::SETTING_FORM_CANDIDATE_ONLY, null);
    }

    /**
     * Получить настройку параметров состояний
     *
     * @param bool|mixed $flag
     * @return mixed
     */
    public function setPresetFormCandidateOnly($flag)
    {
        $this->setSetting(static::SETTING_FORM_CANDIDATE_ONLY, (bool)$flag);
    }

    /**
     * Получить настройку редиректов по завершении тестирований
     *
     * @return mixed
     */
    public function getRedirectSuccess()
    {
        return $this->getSetting(static::SETTING_REDIRECT_SUCCESS, null);
    }

    /**
     * Установить настройку редиректов по завершении тестирований
     *
     * @param $redirect
     */
    public function setRedirectSuccess($redirect)
    {
        $this->setSetting(static::SETTING_REDIRECT_SUCCESS, $redirect);
    }

    const STATE_PARAM_DELIMITER = ';';
    const STATE_PARAM_LIMIT = 150; // 150 символов ограчниение по длине

    /**
     * Получить настройку параметров состояний
     *
     * @return mixed
     */
    public function getStateParams()
    {
        return $this->getSetting(static::SETTING_STATE_PARAMS, null);
    }

    /**
     * Установить настройку редиректов по завершении тестирований
     *
     * @param string $params
     */
    public function setStateParams($params)
    {
        if (is_string($params)) {
            $params = array_map('trim', explode(self::STATE_PARAM_DELIMITER, $params));
        }

        $params = array_filter($params, function ($el) {
            $el = trim($el);
            return !empty($el);
        });

        $this->setSetting(static::SETTING_STATE_PARAMS, $params);
    }

    /**
     * Валидатор для строки параметров
     *
     * @param string $attribute
     */
    public function validateStateParams($attribute)
    {
        $params = $this->getStateParams();

        if (mb_strlen(implode(self::STATE_PARAM_DELIMITER, $params)) > self::STATE_PARAM_LIMIT) {
            $this->addError($attribute, sprintf('Превышена максимальная длина (%d)', self::STATE_PARAM_LIMIT));
        }
    }

    /**
     * Получает параметры состояния для кандидата в проекте из переданной строки с параметрами
     * Источинком стрки может быть как стандартный Yii::$app->req...queryString, так и софрмированный вручную
     *
     * @param $query
     *
     * @return array
     */
    public function getStateParamsFromQuery($query)
    {
        if ($stateParams = $this->getStateParams()) {
            $queryAsArray = [];
            parse_str($query, $queryAsArray);

            return array_filter(
                $queryAsArray,
                function ($val, $key) use ($stateParams) {
                    return in_array($key, $stateParams);
                }, ARRAY_FILTER_USE_BOTH);
        }
        return [];

    }

    /**
     * Получить настройку неудачных редиректов по завершении тестирований
     *
     * @return mixed
     */
    public function getRedirectFail()
    {
        $failUrl = $this->getSetting(static::SETTING_REDIRECT_FAIL, null);
        return !empty($failUrl) ? $failUrl : $this->getRedirectSuccess();
    }

    /**
     * Установить настройку неудачных редиректов по завершении тестирований
     *
     * @param $redirect
     */
    public function setRedirectFail($redirect)
    {
        $this->setSetting(static::SETTING_REDIRECT_FAIL, $redirect);
    }

    /**
     * @return string
     */
    public function getLetterSubject()
    {
        $letterSubject = $this->getAttribute('letter_subject');

        if ($letterSubject === null) {
            return $this->getDefaultLetterSubject();
        }

        return $letterSubject;
    }

    /**
     * @param string $letterSubject
     */
    public function setLetterSubject($letterSubject)
    {
        $this->setAttribute('letter_subject', $letterSubject);
    }

    /**
     * @return string
     */
    public function getLetterTemplate()
    {
        $letterTemplate = $this->getAttribute('letter_template');

        if ($letterTemplate === null) {
            return $this->getDefaultLetterTemplate();
        }

        return $letterTemplate;
    }

    /**
     * @param string $letterTemplate
     */
    public function setLetterTemplate($letterTemplate)
    {
        $this->setAttribute('letter_template', $letterTemplate);
    }

    /**
     * @return int Кол-во тестов в проекте
     */
    public function getTestsCount()
    {
        return (int)$this->getAttribute('tests_count');
    }

    /**
     * @param int $testsCount
     */
    public function setTestsCount($testsCount)
    {
        $this->setAttribute('tests_count', (int)$testsCount);
    }

    /**
     * @return int Кол-во кандидатов в проекте
     */
    public function getCandidatesCount()
    {
        return (int)$this->getAttribute('candidates_count');
    }

    /**
     * @return int Кол-во завершённых тестов кандидатами
     */
    public function getFinishedTestsCount()
    {
        return (int)$this->getAttribute('finished_tests_count');
    }

    /**
     * @param int $count
     */
    public function updateFinishedTestsCount($count)
    {
        $this->updateCounters([
            'finished_tests_count' => $count,
        ]);
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getProjectsUsersRelation()
    {
        return $this->hasMany(ProjectUser::class, ['project_id' => 'id']);
    }


    /**
     * @return \yii\db\ActiveQuery
     */
    public function getProjectsUsersTestsRelation() {
        return $this->hasMany(ProjectUserTest::class, ['project_id' => 'id']);
    }



    /**
     * @return ProjectUser[] ActiveRecordCollection
     */
    public function getProjectsUsers()
    {
        return $this->findRelation('projectsUsers');
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getProjectsTestsRelation()
    {
        return $this->hasMany(ProjectTest::class, ['project_id' => 'id']);
    }

    /**
     * @return ProjectTest[] ActiveRecordCollection
     */
    public function getProjectsTests()
    {
        return $this->findRelation('projectsTests');
    }

    /**
     * @return $this
     */
    public function getTestsRelation()
    {
        return $this->hasMany(TestInstrument::class, ['id' => 'test_id'])->viaTable('projects_tests', ['project_id' => 'id']);
    }

    /**
     * @return \App\Lib\Db\ActiveRecordCollection
     */
    public function getTests()
    {
        return $this->findRelation('tests');
    }

    /**
     * @return array Список ID-шников назначенных тестов
     */
    public function getTestsIds()
    {
        if ($this->_testsIds === null) {
            $this->_testsIds = $this->getProjectsTests()->pluck('testId');
        }

        return $this->_testsIds;
    }

    /**
     * @param array $testsIds
     */
    public function setTestsIds($testsIds)
    {
        $testsIds         = is_array($testsIds) && $testsIds ? array_map('intval', $testsIds) : [];
        $this->_saveTests = $testsIds ? Test::find()->accessFilter($this->getClient())->andWhere( // TODO вынести проверку доступности в валидацию формы
            [
                'id'           => $testsIds,
                'is_published' => true,
            ]
        )->indexBy('id')->collect() : [];
        $this->_testsIds  = empty($this->_saveTests) ? [] : $this->_saveTests->pluck('id');
    }

    /**
     * @return array
     */
    public function getFormsIds()
    {
        if ($this->_formsIds === null) {
            $this->_formsIds = array_filter($this->getProjectsTests()->pluck('formId', 'testId'));
        }

        return $this->_formsIds;
    }

    /**
     * @param array $formsIds
     */
    public function setFormsIds(array $formsIds)
    {
        $formsIds = array_filter($formsIds);
        if ($formsIds) {
            $forms = Form::find()->accessFilter()// TODO вынести проверку доступности в валидацию формы
            ->andWhere([
                'id'           => array_values($formsIds),
                'is_published' => true
            ])
                ->indexBy('id')
                ->all();

            foreach ($formsIds as $testId => $formId) {
                if (isset($forms[$formId])) {
                    $this->_saveForms[$testId] = $forms[$formId];
                    $this->_formsIds[$testId]  = $formId;
                }
            }
        }
    }

    /**
     * @param string $attribute
     */
    public function validateTestsIds($attribute)
    {
        $testIds = $this->getTestsIds();
        if (empty($testIds)) {
            $this->addError($attribute, 'Необходимо указать хотябы один инструмент тестирования');
        }

        // Если проект уже создан и есть тесты, которые кандидаты начали проходить/завершили, то запретить снимать эти тесты
        if (!$this->getIsNewRecord()) {
            $usedTestIds = ProjectUserTest::find()
                ->where([
                    'project_id' => $this->getId(),
                ])
                ->andWhere('status > 0')
                ->select('test_id')
                ->distinct()
                ->collect()
                ->pluck('test_id');

            $testIds = array_flip($testIds);
            $tests   = $this->getTestsRelation()->indexBy('id')->all();

            foreach ($usedTestIds as $usedTestId) {
                if (!isset($testIds[$usedTestId])) {
                    $this->addError($attribute, sprintf('«%s» нельзя снять, т.к. он уже используется кандидатами', $tests[$usedTestId]->getName()));
                    return;
                }
            }
        }
    }

    public function validatePresetFormId($attribute)
    {
        $formId = $this->getPresetFormId();
        if (empty($formId)) return;

        // Если форма указана, то надо проверить что она: доступна и не используется в инструментах тестирований
        if (in_array($formId, $this->getFormsIds())) {
            $this->addError($attribute, _('Данная анкета уже используется в инструментах тестирования проекта'));
        }

        $form = Form::find()->accessFilter()->andWhere(['id' => $formId])->one();
        if (!$form) {
            $this->addError($attribute, _('Указанная форма недоступна'));
        }

        if (!$form->getIsPublished()) {
            $this->addError($attribute, _('Указанная форма неопубликована'));
        }

        if ($formId != $this->getOldDataAttribute('preset_form_id') && $this->getCandidatesCount() > 0) {
            $this->addError($attribute, _('В данном проекте уже есть кандидаты'));
        }

        return;
    }

    /**
     * Получить предустановленную форму
     *
     * @return mixed
     */
    public function getPresetFormId()
    {
        $presetFormID = $this->getDataAttribute('preset_form_id');
        return ($presetFormID) ? $presetFormID : null;
    }

    /**
     * Установить предустановленную биодату
     *
     * @param $formID
     */
    public function setPresetFormId($formID)
    {
        $this->setDataAttribute('preset_form_id', $formID);
        if ($formID) {
        }
    }

    /** @var Form */
    private $presetForm = null;

    /**
     * Получить предустановленную форму
     *
     * @return Form|array|null|\yii\db\ActiveRecord
     */
    public function getPresetForm()
    {
        $formID = $this->getPresetFormId();
        if (!$formID) return null;
        if ($this->presetForm) return $this->presetForm;

        $this->presetForm = Form::findOne(['id' => (int)$formID]);
        return $this->presetForm;
    }

    /**
     * @return int Прогресс заполнения
     */
    public function getProgress()
    {
        $testCount       = $this->getTestsCount();
        $candidatesCount = $this->getCandidatesCount();

        if ($testCount > 0 && $candidatesCount > 0) {
            return round($this->getFinishedTestsCount() / ($testCount * $candidatesCount) * 100, 0, PHP_ROUND_HALF_UP);
        }

        return 0;
    }

    /**
     * @return bool
     */
    public function start()
    {
        if (!$this->isStatusStarted() && !$this->isExpired()) {
            $this->setStatus(static::STATUS_STARTED);
            return (bool)$this->update(false);
        }

        return false;
    }

    /**
     * @return bool
     */
    public function stop()
    {
        if ($this->isStatusStarted()) {
            $this->setStatus(static::STATUS_STOPPED);
            return (bool)$this->update(false);
        }

        return false;
    }

    /**
     * @return bool
     */
    public function finish()
    {
        $this->setStatus(static::STATUS_FINISHED);
        return $this->update(false, null, function () {
            $this->expireProjectUsersTests();
        });
    }

    /**
     * Завершить прохождения кандидатов
     */
    protected function expireProjectUsersTests()
    {
        $projectUsersTests = ProjectUserTest::find()
            ->where([
                'project_id' => $this->getId(),
            ])
            ->andWhere(['not in', 'status', [ProjectUserTest::STATUS_FINISHED, ProjectUserTest::STATUS_STARTED]])
            ->all();

        /** @var ProjectUserTest $projectUserTest */
        foreach ($projectUsersTests as $projectUserTest) {
            $projectUserTest->expire();
        }
    }

    /**
     * @return string
     */
    protected function getDefaultLetterSubject()
    {
        return 'Пройдите тестирование';
    }

    /**
     * @return string
     */
    protected function getDefaultLetterTemplate()
    {
        return <<<HTML
Здравствуйте, {{ user.first_name }}!

{{ tests }}
**********************************************************************
Пройдите тестирование «{{ test.name }}».

Для этого перейдите по ссылке:
{{ link }}
**********************************************************************
{{ /tests }}
HTML;
    }

    /**
     * Сохранение связей проекта с тестами
     */
    protected function saveTests()
    {
        if ($this->_saveTests !== null) {
            $testsCount = 0;

            // Пришли тесты на сохранение, надо найти diff — сохранить новые и удалить не пришедшие
            $projectTests = $this->getProjectsTestsRelation()->indexBy('test_id')->collect();

            if (count($this->_saveTests) > 0) {
                /** @var ProjectTest $projectTest */
                foreach ($projectTests as $projectTest) {
                    $testId = $projectTest->getTestId();
                    if (isset($this->_saveTests[$testId])) {
                        // Тест уже существует у проекта, возможно необходимо обновить форму
                        ++$testsCount;
                        if (isset($this->_saveForms[$testId])) {
                            if ($projectTest->getFormId() !== $this->_saveForms[$testId]->getId()) {
                                $projectTest->setForm($this->_saveForms[$testId]);
                                $projectTest->update(false);
                            }
                        } elseif ($projectTest->hasFrom()) {
                            $projectTest->setFormId(null);
                            $projectTest->update(false);
                        }
                    } else {
                        $projectTest->delete();
                    }
                }

                /** @var Test $test */
                foreach ($this->_saveTests as $test) {
                    $testId = $test->getId();
                    if (!isset($projectTests[$testId])) {
                        $form = isset($this->_saveForms[$testId]) ? $this->_saveForms[$testId] : null;
                        ProjectTest::create($this, $test, $form);
                        ++$testsCount;
                    }
                }
            } else {
                // Если не пришло ни одной связи, надо удалить все связи проекта с тестами
                // Нельзя вызвать ProjectTest::deleteAll(['project_id' => $this->getId()]);
                // т.к. в этом случае не выполнится onAfterDelete

                /** @var ProjectTest $projectTest */
                foreach ($projectTests as $projectTest) {
                    $projectTest->delete();
                }
            }

            $this->_saveTests = null;

            if ($this->getTestsCount() !== $testsCount) {
                $this->setTestsCount($testsCount);
                $this->update(false);
            }
        }
    }
}