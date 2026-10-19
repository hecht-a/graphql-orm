# A form creating an entity

This page builds a form creating a task of a project: a title, and an assignee searched among the users as one types.
The controller persists the task, flushes, and tells the outcome with a flash message, the API refusing the task
included. It uses the `Task` and `User` entities of
[From HttpClient to the bundle](from-http-client.md#with-the-bundle), with a dialect that writes (Data API Builder,
Hasura or PostGraphile, see
[the mutations of each dialect](../writing.md#the-mutations-of-each-dialect)).

## The form type

```bash
composer require symfony/form symfony/ux-autocomplete
```

```php
use GraphqlOrm\Form\GraphqlEntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class TaskType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class)
            ->add('dueDate', DateType::class, ['required' => false, 'input' => 'datetime_immutable'])
            ->add('assignee', GraphqlEntityType::class, [
                'class' => User::class,
                'autocomplete' => true,
                'search_fields' => ['name', 'email'],
                'choice_label' => 'name',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Task::class]);
    }
}
```

The autocomplete field sends what the user types to the endpoint of the bundle, which needs its routes:

```yaml
# config/routes/graphql_orm.yaml
graphql_orm:
    resource: '@GraphqlOrmBundle/Resources/config/routes.php'
```

Restrict `^/_graphql_orm/autocomplete` as the pages using the form are, e.g. with an `access_control`. Without
`autocomplete`, the field lists every user with one query: limit them with `query_builder` (see
[forms](../forms.md#options)).

## The controller

```php
use GraphqlOrm\Exception\FlushException;
use GraphqlOrm\GraphqlManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

final class TaskCreateController extends AbstractController
{
    public function __construct(
        private GraphqlManagerInterface $manager,
        private LoggerInterface $logger,
    ) {}

    #[Route('/projects/{projectId}/tasks/new', name: 'task_new', methods: ['GET', 'POST'])]
    public function __invoke(int $projectId, Request $request): Response
    {
        $task = new Task();
        $task->projectId = $projectId;

        $form = $this->createForm(TaskType::class, $task);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->manager->persist($task);

            try {
                $this->manager->flush(); // createTask(item: { title: ..., project_id: ..., assignee_id: ... }) { id }
            } catch (FlushException $exception) {
                $this->logger->error('The task was not created.', ['exception' => $exception]);
                $this->addFlash('danger', 'The task could not be created. Please try again.');

                return $this->render('task/new.html.twig', ['form' => $form], new Response(status: 422));
            }

            $this->addFlash('success', \sprintf('Task #%d created.', $task->id));

            return $this->redirectToRoute('project_tasks', ['projectId' => $projectId]);
        }

        return $this->render('task/new.html.twig', ['form' => $form]);
    }
}
```

- `persist()` schedules the task, `flush()` sends the mutation and sets `$task->id` from the response (see
  [writing entities](../writing.md)).
- The assignee is written through its `joinField`, `assignee_id`: a relation without one throws a `LogicException`
  (see [relations](../writing.md#relations)).
- The data of the form is the `User` chosen, loaded by its identifier on submission; only that one is loaded to render
  the form again.

## When the API refuses the task

A GraphQL API has no transaction: `flush()` throws a `FlushException` telling how many mutations were applied, and the
error of each one that failed (see [failures](../writing.md#failures)). Here there is one mutation: nothing was written.
The exception also wraps an unreachable API.

Its message quotes the errors of the API, which may hold submitted values: log it, and show the user a message of your
own. Validate the form with the Symfony Validator (a `NotBlank` and a `Length` on the title) to refuse what the API
would refuse before any mutation is sent.

## The template

```twig
{% extends 'base.html.twig' %}

{% block body %}
    {% for type, messages in app.flashes %}
        {% for message in messages %}
            <div class="alert alert-{{ type }}">{{ message }}</div>
        {% endfor %}
    {% endfor %}

    {{ form_start(form) }}
        {{ form_row(form.title) }}
        {{ form_row(form.dueDate) }}
        {{ form_row(form.assignee) }}
        <button type="submit" class="btn btn-primary">Create</button>
    {{ form_end(form) }}
{% endblock %}
```

## See also

- [Forms](../forms.md#autocomplete)
- [Writing entities](../writing.md)
- [Profiler](../profiler.md): the search queries have the origin `autocomplete`, the mutations `flush`
