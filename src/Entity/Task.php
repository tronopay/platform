<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\HeaderParameter;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Header as OpenApiHeader;
use ApiPlatform\OpenApi\Model\MediaType;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\Response as OpenApiResponse;
use App\Dto\CreateTaskRequest;
use App\Dto\CreateTaskResponse;
use App\Dto\GetTaskInvoiceResponse;
use App\Dto\GetTaskResultResponse;
use App\Helper\AcceptTaskProcessor;
use App\Helper\DeleteTaskProcessor;
use App\Helper\DiffHelper;
use App\Helper\GetInvoiceProvider;
use App\Helper\GetResultProvider;
use App\Helper\GetTaskResultFileProvider;
use App\Helper\RejectTaskProcessor;
use App\Helper\CreateTaskProcessor;
use App\Repository\TaskRepository;
use App\Validator\ValidCoordinates;
use App\Validator\ValidTronAddress;
use App\ValueObject\Coordinates;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: TaskRepository::class)]
#[ApiResource(

    operations: [
        new GetCollection(),
        new Get(),
        new Post(
            validationContext: ['groups' => ['Default', 'task:create']],
            input: CreateTaskRequest::class,
            output: CreateTaskResponse::class,
            processor: CreateTaskProcessor::class,
        ),
        new Delete(
            status: 200,
            openapi: new OpenApiOperation(
                summary: 'Cancel the task (soft delete).',
                description: 'Changes the task status to "Canceled" instead of deleting the record.',
            ),
            processor: DeleteTaskProcessor::class,
            parameters: [
                'X-Task-Password' => new HeaderParameter(
                    key: 'X-Task-Password',
                    schema: ['type' => 'string'],
                    description: 'The task password specified when the task was created.',
                    required: true,
                ),
            ],
        ),
        new Get(
            uriTemplate: '/tasks/{id}/invoice',
            uriVariables: ['id'],
            status: 200,
            openapi: new OpenApiOperation(
                summary: 'Get invoice details for the task.',
                description: 'Get invoice details, including the wallet address and the payment amount.',
            ),
            output: GetTaskInvoiceResponse::class,
            provider: GetInvoiceProvider::class,
            parameters: [
                'X-Task-Password' => new HeaderParameter(
                    key: 'X-Task-Password',
                    schema: ['type' => 'string'],
                    description: 'The task password specified when the task was created.',
                    required: true,
                ),
            ],
        ),
        new Get(
            uriTemplate: '/tasks/{id}/result',
            uriVariables: ['id'],
            status: 200,
            openapi: new OpenApiOperation(
                summary: 'Get the task execution results.',
                description: 'Get the task execution results from the worker: text content or some files.',
            ),
            output: GetTaskResultResponse::class,
            provider: GetResultProvider::class,
            parameters: [
                'X-Task-Password' => new HeaderParameter(
                    key: 'X-Task-Password',
                    schema: ['type' => 'string'],
                    description: 'The task password specified when the task was created..',
                    required: true,
                ),
            ],
        ),
        new Get(
            uriTemplate: '/tasks/{id}/result/files/{fileId}',
            uriVariables: [
                'id' => new Link(
                    parameterName: 'id',
                    fromClass: Task::class,
                    identifiers: ['id'],
                    description: 'The task identifier.',
                ),
                'fileId' => new Link(
                    parameterName: 'fileId',
                    fromClass: TaskResultFile::class,
                    identifiers: ['id'],
                    description: 'The result file identifier.',
                ),
            ],
            status: 200,
            openapi: new OpenApiOperation(
                responses: [
                    200 => new OpenApiResponse(
                        description: 'The raw contents of the file.',
                        content: new \ArrayObject([
                            'application/octet-stream' => new MediaType(
                                schema: new \ArrayObject(['type' => 'string', 'format' => 'binary']),
                            ),
                        ]),
                        headers: new \ArrayObject([
                            'Content-Disposition' => new OpenApiHeader(
                                description: 'Set to "attachment" with the original file name.',
                                schema: ['type' => 'string'],
                            ),
                        ]),
                    ),
                    403 => new OpenApiResponse(
                        description: 'The task password is missing or invalid.',
                    ),
                ],
                summary: 'Download a task result file.',
                description: 'Download one of the files attached to the task result. The file is sent with its own content type and as an attachment.',
            ),
            output: false,
            contentNegotiation: false,
            name: 'app_api_task_result_file',
            provider: GetTaskResultFileProvider::class,
            parameters: [
                'X-Task-Password' => new HeaderParameter(
                    key: 'X-Task-Password',
                    schema: ['type' => 'string'],
                    description: 'The task password specified when the task was created.',
                    required: true,
                ),
            ],
            extraProperties: ['_api_disable_swagger_provider' => true],
        ),
        new Post(
            uriTemplate: '/tasks/{id}/accept',
            uriVariables: ['id'],
            status: 200,
            openapi: new OpenApiOperation(
                summary: 'Accept the task results.',
                description: 'Accept results and finish the task. The escrowed price is paid out to the '
                    .'worker\'s TRON address by the payouts process (see app:payout:prepare); '
                    .'accepting does not transfer funds by itself.',
            ),
            input: false,
            processor: AcceptTaskProcessor::class,
            parameters: [
                'X-Task-Password' => new HeaderParameter(
                    key: 'X-Task-Password',
                    schema: ['type' => 'string'],
                    description: 'The task password specified when the task was created.',
                    required: true,
                ),
            ],
        ),
        new Post(
            uriTemplate: '/tasks/{id}/reject',
            uriVariables: ['id'],
            status: 200,
            openapi: new OpenApiOperation(
                summary: 'Reject the task results.',
                description: 'Reject results and finish the task. The escrowed price is refunded to the '
                    .'refund address by the payouts process (see app:payout:prepare); '
                    .'rejecting does not transfer funds by itself.',
            ),
            input: false,
            processor: RejectTaskProcessor::class,
            parameters: [
                'X-Task-Password' => new HeaderParameter(
                    key: 'X-Task-Password',
                    schema: ['type' => 'string'],
                    description: 'The task password specified when the task was created.',
                    required: true,
                ),
            ],
        ),
    ],
    normalizationContext: ['groups' => ['task:read']],
    denormalizationContext: ['groups' => ['task:create', 'task:update']],
)]
class Task implements PasswordAuthenticatedUserInterface
{
    const TRX_PRICE_MIN = 10.0;
    const PRICE_MIN = 10_000_000;

    const GROUP_READ = 'task:read';
    const GROUP_CREATE = 'task:create';
    const GROUP_UPDATE = 'task:update';

    protected ?TaskComment $currentComment = null;

    public function __construct(
        #[Groups([self::GROUP_READ])]
        #[ORM\Id]
        #[ORM\Column(type: 'uuid')]
        private ?Uuid               $id = null,

        #[Assert\NotBlank(groups: [self::GROUP_CREATE, self::GROUP_UPDATE])]
        #[Groups([self::GROUP_READ, self::GROUP_CREATE, self::GROUP_UPDATE])]
        #[ORM\Column(type: Types::TEXT)]
        private ?string             $statement = null,

        #[ORM\Column(length: 255)]
        private ?string             $password = null,

        #[Groups([self::GROUP_READ])]
        #[ORM\Column]
        private ?\DateTimeImmutable $createdAt = null,

        #[Groups([self::GROUP_READ])]
        #[ORM\Column]
        private ?\DateTimeImmutable $updatedAt = null,

        #[Groups([self::GROUP_READ])]
        #[ORM\Column(enumType: TaskStatus::class)]
        private ?TaskStatus         $status = TaskStatus::Created,

        #[Groups([self::GROUP_READ, self::GROUP_CREATE])]
        #[ORM\Column(enumType: TaskType::class)]
        private ?TaskType           $type = TaskType::Other,

        #[Assert\NotBlank(allowNull: true, groups: [self::GROUP_CREATE, self::GROUP_UPDATE])]
        #[ValidCoordinates(groups: [self::GROUP_CREATE, self::GROUP_UPDATE])]
        #[Groups([self::GROUP_READ, self::GROUP_CREATE, self::GROUP_UPDATE])]
        #[ORM\Column(type: 'geography', nullable: true, options: ['geometry_type' => 'POINT', 'srid' => 4326])]
        private ?string             $location = null,

        #[Assert\GreaterThanOrEqual(value: self::PRICE_MIN, groups: [self::GROUP_CREATE, self::GROUP_UPDATE])]
        #[Groups([self::GROUP_READ, self::GROUP_CREATE, self::GROUP_UPDATE])]
        #[ORM\Column(type: Types::INTEGER)]
        private int                 $priceSun = self::PRICE_MIN,

        #[ValidTronAddress(groups: [self::GROUP_CREATE, self::GROUP_UPDATE])]
        #[Groups([self::GROUP_READ, self::GROUP_CREATE, self::GROUP_UPDATE])]
        #[ORM\Column(length: 255, nullable: true)]
        private ?string             $refundAddress = null,

        #[Assert\Url(groups: [self::GROUP_CREATE, self::GROUP_UPDATE])]
        #[Groups([self::GROUP_READ, self::GROUP_CREATE, self::GROUP_UPDATE])]
        #[ORM\Column(length: 255, nullable: true)]
        private ?string             $webhook = null,

        #[ORM\Column(nullable: true)]
        private ?\DateTimeImmutable $paymentDeadlineAt = null,

        #[ORM\Column(nullable: true)]
        private ?\DateTimeImmutable $paidAt = null,

        #[ORM\Column(length: 100, nullable: true)]
        private ?string             $paymentTxHash = null,

        #[ORM\OneToOne(targetEntity: Wallet::class, mappedBy: "task", cascade: ["persist"])]
        private ?Wallet             $wallet = null,

        #[ORM\OneToMany(targetEntity: TaskComment::class, mappedBy: "task", cascade: ["persist", "remove"])]
        private array|Collection    $comments = [],

        #[ORM\ManyToOne(targetEntity: Worker::class)]
        #[ORM\JoinColumn(name: "assigned_to_id", nullable: true, onDelete: "SET NULL")]
        private ?Worker             $assignedTo = null,

        #[ORM\Column(name: "assigned_at", nullable: true)]
        private ?\DateTimeImmutable $assignedAt = null,

        #[ORM\Column(name: "execution_deadline_at", nullable: true)]
        private ?\DateTimeImmutable $executionDeadlineAt = null,

        #[ORM\OneToOne(targetEntity: TaskResult::class, inversedBy: "task", cascade: ["persist", "remove"])]
        #[ORM\JoinColumn(name: "result_id", referencedColumnName: "id", nullable: true)]
        private ?TaskResult         $result = null,
    )
    {
        $this->id = Uuid::v7();

        $now = new \DateTimeImmutable();
        $this->updatedAt = $now;
        if ($this->createdAt == null) {
            $this->createdAt = $now;
        }

        // The serializer fills promoted constructor arguments directly, bypassing setLocation().
        $this->setLocation($this->location);
        // Create wallet info without address
        $this->setWallet(new Wallet(task: $this));
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function setId(Uuid $id): static
    {
        $this->id = $id;

        return $this;
    }

    public function getStatement(): ?string
    {
        return $this->statement;
    }

    public function setStatement(string $statement): static
    {
        $this->statement = $statement;

        return $this;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    public function getStatus(): ?TaskStatus
    {
        return $this->status;
    }

    public function setStatus(?TaskStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getType(): ?TaskType
    {
        return $this->type;
    }

    public function setType(?TaskType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getLocation(): ?string
    {
        return $this->location;
    }

    /**
     * Accepts "latitude, longitude" (e.g. "51.500675, -0.143105") or a GeoJSON
     * Point, and stores the GeoJSON text expected by the geography column.
     */
    public function setLocation(?string $location): static
    {
        $this->location = null === $location ? null : Coordinates::normalize($location);

        return $this;
    }

    public function getPriceSun(): int
    {
        return $this->priceSun;
    }

    public function setPriceSun(int $priceSun): static
    {
        $this->priceSun = $priceSun;

        return $this;
    }
    public function getRefundAddress(): ?string
    {
        return $this->refundAddress;
    }

    public function setRefundAddress(?string $refundAddress): static
    {
        $this->refundAddress = $refundAddress;

        return $this;
    }

    public function getWebhook(): ?string
    {
        return $this->webhook;
    }

    public function setWebhook(?string $webhook): static
    {
        $this->webhook = $webhook;

        return $this;
    }

    public function getPaymentDeadlineAt(): ?\DateTimeImmutable
    {
        return $this->paymentDeadlineAt;
    }

    public function setPaymentDeadlineAt(?\DateTimeImmutable $paymentDeadlineAt): static
    {
        $this->paymentDeadlineAt = $paymentDeadlineAt;

        return $this;
    }

    public function getPaidAt(): ?\DateTimeImmutable
    {
        return $this->paidAt;
    }

    public function setPaidAt(?\DateTimeImmutable $paidAt): static
    {
        $this->paidAt = $paidAt;

        return $this;
    }

    public function getPaymentTxHash(): ?string
    {
        return $this->paymentTxHash;
    }

    public function setPaymentTxHash(?string $paymentTxHash): static
    {
        $this->paymentTxHash = $paymentTxHash;

        return $this;
    }

    public function getWallet(): ?Wallet
    {
        return $this->wallet;
    }

    public function setWallet(?Wallet $wallet): Task
    {
        $this->wallet = $wallet;

        return $this;
    }

    public function getComments(): Collection|array
    {
        return $this->comments;
    }

    public function setComments(Collection|array $comments): static
    {
        $this->comments = $comments;

        return $this;
    }

    public function getCommentValue(): string
    {
        if ($this->currentComment === null) {
            return '';
        }

        return $this->currentComment->getComment();
    }

    public function setCommentValue(?string $comment): static
    {
        $this->getOrCreateCurrentCommentsObject()->setComment($comment);

        return $this;
    }

    public function getOrCreateCurrentCommentsObject(): TaskComment
    {
        if (!$this->currentComment) {
            $this->currentComment = new TaskComment();
            $this->currentComment->setTask($this);
            $this->getComments()->add($this->currentComment);
        }

        return $this->currentComment;
    }

    public function getAssignedTo(): ?Worker
    {
        return $this->assignedTo;
    }

    public function setAssignedTo(?Worker $assignedTo): static
    {
        $this->assignedTo = $assignedTo;

        return $this;
    }

    public function getAssignedAt(): ?\DateTimeImmutable
    {
        return $this->assignedAt;
    }

    public function setAssignedAt(?\DateTimeImmutable $assignedAt): static
    {
        $this->assignedAt = $assignedAt;

        return $this;
    }

    public function getExecutionDeadlineAt(): ?\DateTimeImmutable
    {
        return $this->executionDeadlineAt;
    }

    public function setExecutionDeadlineAt(?\DateTimeImmutable $executionDeadlineAt): static
    {
        $this->executionDeadlineAt = $executionDeadlineAt;

        return $this;
    }

    public function getResult(): ?TaskResult
    {
        return $this->result;
    }

    public function setResult(?TaskResult $result): static
    {
        $this->result = $result;

        return $this;
    }

    public static function diffFormat(): array
    {
        return [
            'status'             => array_flip(TaskStatus::forStatusSelect()),
            'type'               => array_flip(TaskType::forStatusSelect()),

            'createdAt'          => DiffHelper::FORMAT_DATE_TIME_FULL,
            'updatedAt'          => DiffHelper::FORMAT_DATE_TIME_FULL,
            'assignedAt'         => DiffHelper::FORMAT_DATE_TIME_FULL,
            'executionDeadlineAt'=> DiffHelper::FORMAT_DATE_TIME_FULL,
            'assignedTo'         => DiffHelper::FORMAT_REMOVE,
            'result'             => DiffHelper::FORMAT_REMOVE,
            'priceSun'           => DiffHelper::FORMAT_MONEY_COIN,
            'refundAddress'      => DiffHelper::FORMAT_REMOVE,
            'webhook'            => DiffHelper::FORMAT_REMOVE,
            'password'           => DiffHelper::FORMAT_REMOVE,
            'comments'           => DiffHelper::FORMAT_REMOVE,
            'wallet'             => [
                'task' => DiffHelper::FORMAT_REMOVE,
            ]
        ];
    }
}
