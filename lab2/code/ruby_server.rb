# frozen_string_literal: true

require 'bunny'
require 'securerandom'
require 'json'
user = 'guest'
password = 'guest'
host = 'rabbitmq:5672'
queue_name = 'crypto-puzzle-inquiries'
queue_cancel_name = 'crypto-cancel-requests'
queue_servicediscovery_name = 'crypto-service-discovery'
reply_queue_name = 'crypto-puzzle-responses'

connection = Bunny.new "amqp://#{user}:#{password}@#{host}"
connection.start

channel = connection.create_channel
exchange = channel.default_exchange
queue = channel.queue(queue_name, auto_delete: false, durable: true)
queue_cancel = channel.fanout(queue_cancel_name, auto_delete: false)
queue_servicediscovery = channel.fanout(queue_servicediscovery_name, auto_delete: false)
reply_queue = channel.queue(reply_queue_name, exclusive: false)
class Integer
  N_BYTES = [42].pack('i').size
  N_BITS = N_BYTES * 16
  MAX = 2 ** (N_BITS - 2) - 1
  MIN = -MAX - 1
end

lock = Mutex.new
condition = ConditionVariable.new
slaves_count = 0
begin
  loop do
    puts 'Press Ctrl+C to exit'
    puts 'Enter difficulty of puzzle from 1 to 8:'

    difficulty = $stdin.gets.to_i
    if (1..8).include?(difficulty)
	  
      reply_queue.subscribe do |_delivery_info, _properties, payload|
	    payload = payload.gsub('"', '')
        puts "Discovery response is: #{payload}"
		if (payload == "discovered")
		  slaves_count += 1
          puts "Got slave discovered: now slaves_count = #{slaves_count}"
		else		
          puts "Cancelling all pending calculations"
		  lock.synchronize { condition.signal }
		end
      end
      payload_discovery = { request: 'discovery' }
      queue_servicediscovery.publish(payload_discovery.to_json)
      puts "Sent discovery to #{queue_servicediscovery.name}"
	  sleep 1
	  
      puts "SLAVES #{slaves_count}"
	  
	  lim = Integer::MAX #/ 10e10
	  
      batch = lim / slaves_count;
	  
	  for i in 0..(slaves_count-1)
		ista = batch * i;
        iend = ista + batch;		
	  
		from = ista
		to = iend
        puts "Value of local variable is #{ista} #{iend}"
		payload = { string: 'Hello World', difficulty: difficulty, from: from, to: to }
		exchange.publish(payload.to_json, routing_key: queue.name, correlation_id: SecureRandom.uuid, reply_to: reply_queue.name)
        puts "Value of local variable is #{ista} #{iend}"
      end
	  
      lock.synchronize { condition.wait(lock) }	
      payload_cancel = { request: 'cancel_all' }
      queue_cancel.publish(payload_cancel.to_json)  
      slaves_count = 0
    else
      puts "Incorrect value. You've introduced #{difficulty}. Valid range is 1..8"
    end
  end
rescue Interrupt => _e
  channel.close
  connection.close
  exit(0)
end
