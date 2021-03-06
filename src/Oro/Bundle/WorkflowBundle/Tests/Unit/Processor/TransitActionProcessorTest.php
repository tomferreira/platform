<?php

namespace Oro\Bundle\WorkflowBundle\Tests\Unit\Processor;

use Oro\Bundle\WorkflowBundle\Processor\Context\TransitionContext;
use Oro\Bundle\WorkflowBundle\Processor\TransitActionProcessor;
use Oro\Component\ChainProcessor\Exception\ExecutionFailedException;
use Oro\Component\ChainProcessor\ProcessorBag;
use Oro\Component\ChainProcessor\ProcessorFactoryInterface;
use Oro\Component\ChainProcessor\ProcessorInterface;
use Psr\Log\LoggerInterface;

/**
 * Suppressing for stubs and mock classes
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class TransitActionProcessorTest extends \PHPUnit_Framework_TestCase
{
    /** @var ProcessorBag */
    protected $processorBag;

    /** @var ProcessorFactoryInterface|\PHPUnit_Framework_MockObject_MockObject */
    protected $factory;

    /** @var LoggerInterface|\PHPUnit_Framework_MockObject_MockObject */
    protected $logger;

    /** @var TransitActionProcessor */
    protected $processor;

    protected function setUp()
    {
        $this->factory = $this->createMock(ProcessorFactoryInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->processorBag = new ProcessorBag($this->factory);

        $this->processor = new TransitActionProcessor($this->processorBag, $this->logger);
    }

    public function testProperContextCreated()
    {
        self::assertInstanceOf(TransitionContext::class, $this->processor->createContext());
    }

    public function testUnsupportedContextType()
    {
        $context = new UnsupportedContextStub();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Oro\Bundle\WorkflowBundle\Processor\TransitActionProcessor supports only ' .
            'Oro\Bundle\WorkflowBundle\Processor\Context\TransitionContext context, but ' .
            'Oro\Bundle\WorkflowBundle\Tests\Unit\Processor\UnsupportedContextStub given.'
        );

        $this->processor->process($context);
    }

    public function testExecuteProcessors()
    {
        $processor1 = $this->createMock(ProcessorInterface::class);
        $processor2 = $this->createMock(ProcessorInterface::class);

        $this->factory->expects($this->exactly(2))
            ->method('getProcessor')
            ->willReturnOnConsecutiveCalls($processor1, $processor2);

        $this->processorBag->addProcessor('processor1', [], 'action1', null, 20);
        $this->processorBag->addProcessor('processor2', [], 'action1', null, 10);

        $context = new TransitionContext();
        $context->setAction('action1');

        $this->logger->expects($this->exactly(4))
            ->method('debug')
            ->withConsecutive(
                ['Execute processor {processorId}', ['processorId' => 'processor1', 'processorAttributes' => []]],
                ['Context processed.', ['context' => $context->toArray()]],
                ['Execute processor {processorId}', ['processorId' => 'processor2', 'processorAttributes' => []]],
                ['Context processed.', ['context' => $context->toArray()]]
            );

        $processor1->expects($this->once())->method('process')->with($this->identicalTo($context));
        $processor2->expects($this->once())->method('process')->with($this->identicalTo($context));

        $this->processor->process($context);
    }

    public function testExecuteProcessorsFailure()
    {
        $processor1 = $this->createMock(ProcessorInterface::class);
        $processor2 = $this->createMock(ProcessorInterface::class);

        $this->factory->expects($this->exactly(2))
            ->method('getProcessor')
            ->willReturnOnConsecutiveCalls($processor1, $processor2);

        $this->processorBag->addGroup('group1', 'action1', 20);
        $this->processorBag->addGroup('group2', 'action1', 10);
        $this->processorBag->addProcessor('processor1', [], 'action1', 'group1', 20);
        $this->processorBag->addProcessor('processor2', [], 'action1', 'group2', 20);
        $this->processorBag->addProcessor('processor3', [], 'action1', 'group2', 10);

        $context = new TransitionContext();
        $context->setAction('action1');

        $processor1->expects($this->once())->method('process')->with($this->identicalTo($context));
        $processor2->expects($this->once())
            ->method('process')
            ->with($this->identicalTo($context))
            ->willThrowException(new \Exception('Some error.'));

        try {
            $this->processor->process($context);
            $this->fail('An exception expected');
        } catch (ExecutionFailedException $e) {
            $this->assertEquals('Processor failed: "processor2". Reason: Some error.', $e->getMessage());
            $this->assertEquals('processor2', $e->getProcessorId());
            $this->assertEquals('action1', $e->getAction());
            $this->assertEquals('group2', $e->getGroup());
            $this->assertNotNull($e->getPrevious());
            $this->assertEquals('Some error.', $e->getPrevious()->getMessage());
        } catch (\Exception $e) {
            $this->fail(sprintf('ExecutionFailedException expected. Got: %s', get_class($e)));
        }
    }
}
